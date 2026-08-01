<?php

namespace Dowardev\SsoServer\Tests;

use Dowardev\SsoServer\Dpop\ReplayProtection;
use Dowardev\SsoServer\Exceptions\DpopVerificationException;
use Dowardev\SsoServer\Types\SsoConfig;
use Illuminate\Support\Facades\Cache;

class ReplayProtectionTest extends TestCase
{
    private ReplayProtection $protection;

    protected function setUp(): void
    {
        parent::setUp();
        $config = SsoConfig::fromConfig();
        $this->protection = new ReplayProtection($config);
        Cache::flush();
    }

    public function test_check_and_store_allows_new_jti(): void
    {
        $this->protection->checkAndStore('unique-jti-1');

        $this->assertTrue(Cache::has('dpop_jti:unique-jti-1'));
    }

    public function test_check_and_store_throws_on_replay(): void
    {
        $this->protection->checkAndStore('unique-jti-2');

        $this->expectException(DpopVerificationException::class);
        $this->protection->checkAndStore('unique-jti-2');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
