<?php

use App\Console\Commands\CleanupRefreshTokens;
use App\Console\Commands\GenerateJwtKeys;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::registerCommand(app(GenerateJwtKeys::class));
Artisan::registerCommand(app(CleanupRefreshTokens::class));

Schedule::command('tokens:cleanup')->daily();
