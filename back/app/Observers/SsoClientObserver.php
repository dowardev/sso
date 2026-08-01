<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Observers;

use App\Models\SsoClient;
use Illuminate\Support\Facades\Cache;

class SsoClientObserver
{
    public function saved(SsoClient $client): void
    {
        Cache::forget('sso_cors_origins_'.$client->client_id);
        Cache::forget('sso_cors_origins___sso__');
    }

    public function deleted(SsoClient $client): void
    {
        Cache::forget('sso_cors_origins_'.$client->client_id);
        Cache::forget('sso_cors_origins___sso__');
    }
}
