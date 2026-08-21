<?php

// SPDX-License-Identifier: AGPL-3.0-only

use App\Console\Commands\CleanupDpopNonces;
use App\Console\Commands\CleanupRefreshTokens;
use App\Console\Commands\GenerateJwtKeys;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::registerCommand(app(GenerateJwtKeys::class));
Artisan::registerCommand(app(CleanupRefreshTokens::class));
Artisan::registerCommand(app(CleanupDpopNonces::class));
Schedule::command('tokens:cleanup')->daily();
Schedule::command('dpop:cleanup')->everyFiveMinutes();
Schedule::command('verification-tokens:cleanup')->daily();

Schedule::call(function () {
    DB::table('login_attempts')
        ->where('locked_until', '<', now())
        ->orWhere('updated_at', '<', now()->subDays(7))
        ->delete();
})->daily();
