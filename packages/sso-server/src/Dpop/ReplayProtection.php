<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Dpop;

use Dowardev\SsoServer\Exceptions\DpopVerificationException;
use Dowardev\SsoServer\Types\SsoConfig;
use Illuminate\Support\Facades\Cache;

class ReplayProtection
{
    public function __construct(
        private readonly SsoConfig $config,
    ) {}

    /**
     * Check if a JTI has been seen before and store it atomically.
     *
     * Uses Cache::add() instead of Cache::has() + Cache::put() to ensure
     * atomicity across multiple instances sharing a cache backend.
     *
     * Cache::add() is atomic on the following drivers:
     * - redis        → SET key value NX EX ttl
     * - database     → INSERT IGNORE on the cache table
     * - memcached    → Memcached::add()
     * - array        → in-memory set (single-process only)
     *
     * The file driver is NOT atomic across processes (TOCTOU between
     * file_exists and file_put_contents). A boot-time warning in
     * SsoServerServiceProvider alerts operators when a non-atomic driver
     * is configured for multi-instance deployments.
     */
    public function checkAndStore(string $jti): void
    {
        $cacheKey = 'dpop_jti:'.$jti;

        $added = Cache::add($cacheKey, true, $this->config->dpopReplayTtl);

        if (! $added) {
            throw DpopVerificationException::replayDetected();
        }
    }
}
