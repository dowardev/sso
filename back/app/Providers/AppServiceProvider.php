<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Providers;

use App\Models\SsoClient;
use App\Observers\SsoClientObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        SsoClient::observe(SsoClientObserver::class);

        $this->bindDpopAllowedAlgs();
    }

    /**
     * Read DPOP_ALLOWED_ALGS at runtime and write it into the live config
     * repository, so the allowlist can be tightened (['ES256','RS256'] ->
     * ['ES256']) without a redeploy AND without falling foul of Laravel's
     * `config:cache` (which freezes any `env()` call inside a config file
     * to the value at cache time). The config file in
     * `back/config/jwt.php` ships a hard-coded default and never reads
     * `env()` directly; this provider is the only place that does.
     *
     * Default when neither the env var nor a config override is set:
     * ['ES256','RS256'] (post-LBDS-258 dual-allowlist, also the
     * SsoConfig::DEFAULT_DPOP_ALLOWED_ALGS constant on the sso-server
     * side — keep the two in sync, see the cutover runbook).
     */
    private function bindDpopAllowedAlgs(): void
    {
        $envValue = env('DPOP_ALLOWED_ALGS');

        if (is_string($envValue) && trim($envValue) !== '') {
            $resolved = $this->sanitizeDpopAllowedAlgs(array_values(array_filter(array_map(
                'trim',
                explode(',', $envValue)
            ))));

            config()->set('jwt.dpop_allowed_algs', $resolved);

            $this->validateDpopAllowedAlgs($resolved);

            return;
        }

        // No env value, no config file value: keep the dual default.
        if (config('jwt.dpop_allowed_algs') === null) {
            config()->set('jwt.dpop_allowed_algs', ['ES256', 'RS256']);
        }

        config()->set('jwt.dpop_allowed_algs', $this->sanitizeDpopAllowedAlgs(config('jwt.dpop_allowed_algs')));
        $this->validateDpopAllowedAlgs(config('jwt.dpop_allowed_algs'));
    }

    /**
     * Drop any entries the verifier cannot dispatch (anything other than
     * `ES256` / `RS256`). Without this filter, a typo like
     * `DPOP_ALLOWED_ALGS=none,ES256` reaches `supportedAlgs()` and locks
     * every proof out with an `alg=unknown` metric. The verifier itself
     * would only fail later, at `signerForAlgorithm()`; sanitizing here
     * means the bad value never reaches the auth path.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function sanitizeDpopAllowedAlgs(array $values): array
    {
        $supported = ['ES256', 'RS256'];

        return array_values(array_intersect(
            array_map('strval', $values),
            $supported
        ));
    }

    /**
     * Fail fast on a misconfigured allowlist. Today the verifier only
     * dispatches signers for `ES256` and `RS256`; any other value would
     * reach `signerForAlgorithm()` and throw a generic
     * `verification_failed` metric (alg=unknown), which is exactly the
     * silent cutover-metric failure mode the runbook calls out. A
     * `Log::warning` here gives on-call a clear signal without taking
     * the auth path down.
     *
     * @param  array<int, string>  $allowlist
     */
    private function validateDpopAllowedAlgs(array $allowlist): void
    {
        $supported = ['ES256', 'RS256'];
        $unknown = array_values(array_diff($allowlist, $supported));

        if ($unknown !== []) {
            \Illuminate\Support\Facades\Log::warning(
                'DPoP allowlist contains unsupported algorithms: '.implode(',', $unknown)
                .'. Only ES256 and RS256 are wired into the verifier; '
                .'unknown values will fail proof verification with alg=unknown '
                .'and defeat the cutover metric. Update DPOP_ALLOWED_ALGS or '
                .'config("jwt.dpop_allowed_algs") to a subset of '.implode(',', $supported).'.',
                [
                    'dpop_allowlist' => $allowlist,
                    'dpop_unknown_algs' => $unknown,
                ]
            );
        }

        if (in_array('none', $allowlist, true) || $allowlist === []) {
            // Documented safe-default behavior (see supportedAlgs()): an
            // empty allowlist falls back to ['ES256','RS256']. The
            // warning still fires so a `DPOP_ALLOWED_ALGS=` typo is
            // visible.
            \Illuminate\Support\Facades\Log::info(
                'DPoP allowlist resolved empty; falling back to dual default',
                ['dpop_allowlist' => $allowlist]
            );
        }
    }
}
