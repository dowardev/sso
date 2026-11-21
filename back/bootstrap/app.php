<?php

use App\Http\Middleware\ClientCredentialsAuth;
use App\Http\Middleware\EnsureSetupNotCompleted;
use App\Http\Middleware\HandleDynamicCors;
use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\JwtAuth;
use App\Http\Middleware\RefreshToken;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleLoginAttempts;
use App\Http\Middleware\ThrottleRequests;
use App\Http\Middleware\ThrottleTokenEndpoint;
use App\Http\Middleware\VerifyDpopProof;
use App\Support\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            'admin' => IsAdmin::class,
            'jwt.auth' => JwtAuth::class,
            'dpop' => VerifyDpopProof::class,
            'client.credentials' => ClientCredentialsAuth::class,
            'setup.not_completed' => EnsureSetupNotCompleted::class,
            'refresh.token' => RefreshToken::class,
            'throttle.login' => ThrottleLoginAttempts::class,
            'throttle.token' => ThrottleTokenEndpoint::class,
            'throttle' => ThrottleRequests::class,
        ]);

        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every API validation failure carries a stable machine-readable code
        // per field (`error_details`), on top of the existing `errors` map.
        // See odd/tasks/api-error-contract-codes.md.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $validator = $e->validator;

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $validator->errors()->messages(),
                'error_details' => ApiError::buildErrorDetails($validator),
            ], 422);
        });
    })->create();
