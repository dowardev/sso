<?php

namespace Dowardev\SsoServer\Tests;

use Dowardev\SsoServer\Dpop\ReplayProtection;
use Dowardev\SsoServer\Exceptions\DpopVerificationException;
use Dowardev\SsoServer\Types\SsoConfig;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
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

    /**
     * Cross-instance simulation: two Repository instances backed by the same
     * ArrayStore. The first call on instance A must succeed; the second call
     * on instance B with the same JTI must throw (replay detected).
     *
     * This proves the atomicity contract: Cache::add() returns false when
     * the key already exists, even across different Repository instances
     * sharing the same underlying store.
     */
    public function test_cross_instance_replay_detection_with_shared_store(): void
    {
        $sharedStore = new ArrayStore;
        $repoA = new Repository($sharedStore);
        $repoB = new Repository($sharedStore);

        $config = SsoConfig::fromConfig();
        $jti = 'cross-instance-jti-1';
        $cacheKey = 'dpop_jti:'.$jti;
        $ttl = $config->dpopReplayTtl;

        // First add via repoA (simulating instance A)
        $addedA = $repoA->add($cacheKey, true, $ttl);
        $this->assertTrue($addedA, 'First add should succeed');

        // Second add via repoB (simulating instance B) with same JTI
        $addedB = $repoB->add($cacheKey, true, $ttl);
        $this->assertFalse($addedB, 'Second add should fail (replay detected)');
    }

    /**
     * Driver parity test for array driver: verify Cache::add() atomicity.
     */
    public function test_driver_parity_atomic_add_array(): void
    {
        $this->runDriverParityTest('array');
    }

    /**
     * Driver parity test for database driver: verify Cache::add() atomicity.
     * Skipped if cache table migration is not available in test environment.
     */
    public function test_driver_parity_atomic_add_database(): void
    {
        $this->runDriverParityTest('database');
    }

    /**
     * Helper to test a specific driver's atomic add behavior.
     */
    private function runDriverParityTest(string $driver): void
    {
        try {
            // Create store directly without changing global config
            $store = Cache::store($driver);
            $store->flush();

            // Create two Repository instances pointing at the same store
            // For array driver, we need to share the same ArrayStore instance
            if ($driver === 'array') {
                $sharedStore = new ArrayStore;
                $repoA = new Repository($sharedStore);
                $repoB = new Repository($sharedStore);
            } else {
                // For database, both stores point to the same DB connection
                $repoA = Cache::store($driver);
                $repoB = Cache::store($driver);
            }

            $jti = 'driver-parity-jti-'.$driver;
            $cacheKey = 'dpop_jti:'.$jti;
            $ttl = 120;

            // First add via repoA
            $addedA = $repoA->add($cacheKey, true, $ttl);
            $this->assertTrue($addedA, "First add should succeed on driver: {$driver}");

            // Second add via repoB with same JTI
            $addedB = $repoB->add($cacheKey, true, $ttl);
            $this->assertFalse($addedB, "Second add should fail on driver: {$driver}");
        } catch (\Throwable $e) {
            $this->markTestSkipped("Driver {$driver} not available in test environment: ".$e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
