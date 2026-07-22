<?php
// SPDX-License-Identifier: MIT

namespace TuOrg\SsoServer;

use Illuminate\Support\ServiceProvider;
use TuOrg\SsoServer\Auth\RoleChecker;
use TuOrg\SsoServer\Auth\SsoUserResolver;
use TuOrg\SsoServer\Client\SsoServerClient;
use TuOrg\SsoServer\Dpop\DpopVerifier;
use TuOrg\SsoServer\Dpop\ReplayProtection;
use TuOrg\SsoServer\Http\SsoHttpClient;
use TuOrg\SsoServer\Jwt\JwkToPemConverter;
use TuOrg\SsoServer\Jwt\JwksFetcher;
use TuOrg\SsoServer\Jwt\JwtValidator;
use TuOrg\SsoServer\Middleware\SsoAuthMiddleware;
use TuOrg\SsoServer\Middleware\SsoRoleMiddleware;
use TuOrg\SsoServer\Types\SsoConfig;

class SsoServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/sso.php',
            'sso'
        );

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
                __DIR__ . '/../config/sso.php' => config_path('sso.php'),
            ], 'sso-config');
        }

        $this->app['router']->aliasMiddleware('sso.auth', SsoAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('sso.role', SsoRoleMiddleware::class);
    }
}
