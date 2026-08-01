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
use Illuminate\Support\ServiceProvider;

class SsoServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/sso.php',
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
                __DIR__.'/../config/sso.php' => config_path('sso.php'),
            ], 'sso-config');
        }

        $this->app['router']->aliasMiddleware('sso.auth', SsoAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('sso.role', SsoRoleMiddleware::class);
    }
}
