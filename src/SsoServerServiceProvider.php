<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer;

use Dowardev\SsoServer\Auth\RoleChecker;
use Dowardev\SsoServer\Auth\SsoUserResolver;
use Dowardev\SsoServer\Client\SsoServerClient;
use Dowardev\SsoServer\Dpop\DpopVerifier;
use Dowardev\SsoServer\Dpop\ReplayProtection;
use Dowardev\SsoServer\Http\SsoHttpClient;
use Dowardev\SsoServer\Jwt\JwksFetcher;
use Dowardev\SsoServer\Jwt\JwkToPemConverter;
use Dowardev\SsoServer\Jwt\JwtValidator;
use Dowardev\SsoServer\Middleware\SsoAuthMiddleware;
use Dowardev\SsoServer\Middleware\SsoRoleMiddleware;
use Dowardev\SsoServer\Types\SsoConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class SsoServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/sso.php',
            'sso'
        );

        // Resolve SSO_DPOP_ALLOWED_ALGS at runtime so the cutover config
        // flag survives `php artisan config:cache` (which would otherwise
        // freeze any `env()` call inside a config file to the value at
        // cache time). Must run BEFORE the SsoConfig singleton below
        // reads config('sso.dpop_allowed_algs').
        $this->resolveDpopAllowedAlgsConfig();

        $this->app->singleton(SsoConfig::class, fn () => SsoConfig::fromConfig());

        $this->app->singleton(JwkToPemConverter::class);
        $this->app->singleton(JwksFetcher::class);
        $this->app->singleton(JwtValidator::class);
        $this->app->singleton(ReplayProtection::class);
        $this->app->singleton(DpopVerifier::class);
        $this->app->singleton(SsoHttpClient::class);
        $this->app->singleton(SsoUserResolver::class);
        $this->app->singleton(RoleChecker::class);

        $this->app->singleton('sso-server-client', SsoServerClient::class);
        $this->app->singleton(SsoServerClient::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sso.php' => config_path('sso.php'),
            ], 'sso-config');
        }

        $this->warnOnNonAtomicCacheDriver();

        $this->app['router']->aliasMiddleware('sso.auth', SsoAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('sso.role', SsoRoleMiddleware::class);
    }

    /**
     * Emit a warning when the cache driver is non-atomic (file or array).
     *
     * DPoP replay protection relies on Cache::add() atomicity. The file and
     * array drivers are not atomic across processes/pods, so multi-instance
     * deployments will silently accept replayed DPoP proofs.
     */
    private function warnOnNonAtomicCacheDriver(): void
    {
        $driver = config('cache.default');
        $nonAtomicDrivers = ['file', 'array'];

        if (in_array($driver, $nonAtomicDrivers, true)) {
            Log::warning(
                'DPoP replay protection will not work across pods/instances '
                .'because the cache driver "'.$driver.'" is non-atomic. '
                .'Switch CACHE_STORE to redis, database, or memcached for '
                .'multi-instance deployments.'
            );
        }
    }

    /**
     * Read SSO_DPOP_ALLOWED_ALGS at runtime and write it into the live
     * config repository. See AppServiceProvider::bindDpopAllowedAlgs() on
     * the back side for the symmetric pattern and the rationale (avoid
     * config:cache freezing the value).
     */
    private function resolveDpopAllowedAlgsConfig(): void
    {
        $envValue = env('SSO_DPOP_ALLOWED_ALGS');

        if (is_string($envValue) && trim($envValue) !== '') {
            $resolved = $this->sanitizeDpopAllowedAlgs(array_values(array_filter(array_map(
                'trim',
                explode(',', $envValue)
            ))));

            config()->set('sso.dpop_allowed_algs', $resolved);

            $this->validateDpopAllowedAlgs($resolved);
        }
    }

    /**
     * Mirror of AppServiceProvider::sanitizeDpopAllowedAlgs(). Drop any
     * entries the verifier cannot dispatch. Keeps the dual-side defaults
     * in sync — same trim/intersect semantics, same supported set.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function sanitizeDpopAllowedAlgs(array $values): array
    {
        return array_values(array_intersect(
            array_map('strval', $values),
            ['ES256', 'RS256']
        ));
    }

    /**
     * Fail fast on a misconfigured allowlist. The verifier only
     * dispatches signers for `ES256` and `RS256`; an unknown value
     * would reach `signerForAlgorithm()` and throw a generic
     * `verification_failed` metric (alg=unknown), defeating the cutover
     * metric. A `Log::warning` here gives on-call a clear signal
     * without taking the auth path down. Mirrors the back-side
     * `AppServiceProvider::validateDpopAllowedAlgs()`.
     *
     * @param  array<int, string>  $allowlist
     */
    private function validateDpopAllowedAlgs(array $allowlist): void
    {
        $supported = ['ES256', 'RS256'];
        $unknown = array_values(array_diff($allowlist, $supported));

        if ($unknown !== []) {
            Log::warning(
                'SSO DPoP allowlist contains unsupported algorithms: '.implode(',', $unknown)
                .'. Only ES256 and RS256 are wired into the verifier; '
                .'unknown values will fail proof verification with alg=unknown '
                .'and defeat the cutover metric. Update SSO_DPOP_ALLOWED_ALGS or '
                .'config("sso.dpop_allowed_algs") to a subset of '.implode(',', $supported).'.',
                [
                    'sso_dpop_allowlist' => $allowlist,
                    'sso_dpop_unknown_algs' => $unknown,
                ]
            );
        }
    }
}
