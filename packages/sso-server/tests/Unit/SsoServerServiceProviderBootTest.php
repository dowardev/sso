<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Tests\Unit;

use Dowardev\SsoServer\SsoServerServiceProvider;
use Dowardev\SsoServer\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;

class SsoServerServiceProviderBootTest extends TestCase
{
    private string $originalCacheDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCacheDefault = Config::get('cache.default', 'array');
    }

    protected function tearDown(): void
    {
        Config::set('cache.default', $this->originalCacheDefault);
        parent::tearDown();
    }

    public function test_boot_emits_warning_when_cache_driver_is_file(): void
    {
        Config::set('cache.default', 'file');

        Log::spy();
        $this->callWarnOnNonAtomicCacheDriver();

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'DPoP replay protection will not work across pods/instances '.
                'because the cache driver "file" is non-atomic. '.
                'Switch CACHE_STORE to redis, database, or memcached for '.
                'multi-instance deployments.'
            );
    }

    public function test_boot_emits_warning_when_cache_driver_is_array(): void
    {
        Config::set('cache.default', 'array');

        Log::spy();
        $this->callWarnOnNonAtomicCacheDriver();

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'DPoP replay protection will not work across pods/instances '.
                'because the cache driver "array" is non-atomic. '.
                'Switch CACHE_STORE to redis, database, or memcached for '.
                'multi-instance deployments.'
            );
    }

    public function test_boot_does_not_emit_warning_when_cache_driver_is_redis(): void
    {
        Config::set('cache.default', 'redis');

        Log::spy();
        $this->callWarnOnNonAtomicCacheDriver();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_boot_does_not_emit_warning_when_cache_driver_is_database(): void
    {
        Config::set('cache.default', 'database');

        Log::spy();
        $this->callWarnOnNonAtomicCacheDriver();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_boot_does_not_emit_warning_when_cache_driver_is_memcached(): void
    {
        Config::set('cache.default', 'memcached');

        Log::spy();
        $this->callWarnOnNonAtomicCacheDriver();

        Log::shouldNotHaveReceived('warning');
    }

    private function callWarnOnNonAtomicCacheDriver(): void
    {
        $provider = $this->app->getProvider(SsoServerServiceProvider::class);
        $method = new ReflectionMethod($provider, 'warnOnNonAtomicCacheDriver');
        $method->setAccessible(true);
        $method->invoke($provider);
    }
}
