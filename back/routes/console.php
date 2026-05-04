<?php

use App\Console\Commands\GenerateJwtKeys;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::registerCommand(app(GenerateJwtKeys::class));
