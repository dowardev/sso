<?php

use App\Http\Middleware\HandleDynamicCors;
use App\Http\Middleware\RefreshToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(HandleCors::class, HandleDynamicCors::class);
        $middleware->alias([
            'admin' => \App\Http\Middleware\IsAdmin::class,
            'jwt.auth' => \App\Http\Middleware\JwtAuth::class,
            'dpop' => \App\Http\Middleware\VerifyDpopProof::class,
            'client.credentials' => \App\Http\Middleware\ClientCredentialsAuth::class,
            'setup.not_completed' => \App\Http\Middleware\EnsureSetupNotCompleted::class,
            'refresh.token' => RefreshToken::class,
            'throttle.login' => \App\Http\Middleware\ThrottleLoginAttempts::class,
        ]);

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
