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

    public function checkAndStore(string $jti): void
    {
        $cacheKey = 'dpop_jti:'.$jti;

        if (Cache::has($cacheKey)) {
            throw DpopVerificationException::replayDetected();
        }

        Cache::put($cacheKey, true, $this->config->dpopReplayTtl);
    }
}
