<?php
// SPDX-License-Identifier: MIT

namespace Dowardev\SsoServer\Dpop;

use Illuminate\Support\Facades\Cache;
use Dowardev\SsoServer\Exceptions\DpopVerificationException;
use Dowardev\SsoServer\Types\SsoConfig;

class ReplayProtection
{
    public function __construct(
        private readonly SsoConfig $config,
    ) {}

    public function checkAndStore(string $jti): void
    {
        $cacheKey = 'dpop_jti:' . $jti;

        if (Cache::has($cacheKey)) {
            throw DpopVerificationException::replayDetected();
        }

        Cache::put($cacheKey, true, $this->config->dpopReplayTtl);
    }
}
