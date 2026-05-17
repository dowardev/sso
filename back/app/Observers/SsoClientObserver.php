<?php

namespace App\Observers;

use App\Models\SsoClient;
use Illuminate\Support\Facades\Cache;

class SsoClientObserver
{
    public function saved(SsoClient $client): void
    {
        Cache::forget('sso_cors_origins');
    }

    public function deleted(SsoClient $client): void
    {
        Cache::forget('sso_cors_origins');
    }
}