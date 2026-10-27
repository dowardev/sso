<?php

namespace Dowardev\SsoServer\Tests;

use Dowardev\SsoServer\SsoServerServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [SsoServerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('sso.sso_url', 'http://localhost:8001/api');
        $app['config']->set('sso.jwt_issuer', 'http://localhost:8001');
        $app['config']->set('sso.jwks_cache_ttl', 3600);
        $app['config']->set('sso.roles_cache_ttl', 300);
        $app['config']->set('sso.dpop_replay_ttl', 120);
        $app['config']->set('sso.dpop_iat_leeway', 60);
        $app['config']->set('sso.client_id', '1');
        $app['config']->set('sso.role_mappings', [
            'admin' => ['slug' => 'admin'],
        ]);
    }
}
