<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\OAuth2Controller;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SsoClientController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserSessionController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/oauth/token', [OAuth2Controller::class, 'token']);
Route::get('/.well-known/jwks', [AuthController::class, 'jwks']);
Route::get('/session/check', [AuthController::class, 'checkSession']);
Route::post('/session/check', [AuthController::class, 'checkSession']);
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/roles', [RoleController::class, 'index']);

Route::middleware(['jwt.auth', 'client.credentials'])->group(function () {
    Route::get('/users', [UserController::class, 'apiIndex']);
});

Route::middleware('jwt.auth')->group(function () {
    Route::post('/oauth/authorize', [OAuth2Controller::class, 'authorize']);

    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/user-auth', [AuthController::class, 'me']);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
        Route::post('/users/{user}/toggle', [UserController::class, 'toggleAdmin']);

        Route::get('/users/{user}/roles', [UserController::class, 'indexRoles']);
        Route::put('/users/{user}/roles', [UserController::class, 'updateRoles']);

        Route::get('/sessions', [UserSessionController::class, 'index']);
        Route::get('/users/{user}/sessions', [UserSessionController::class, 'userSessions']);
        Route::delete('/sessions/{session}', [UserSessionController::class, 'destroy']);
        Route::delete('/users/{user}/sessions', [UserSessionController::class, 'destroyUserSessions']);

        Route::get('/clients', [SsoClientController::class, 'index']);
        Route::post('/clients', [SsoClientController::class, 'store']);
        Route::put('/clients/{client}', [SsoClientController::class, 'update']);
        Route::delete('/clients/{client}', [SsoClientController::class, 'destroy']);

        Route::get('/roles', [RoleController::class, 'adminIndex']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
    });
});
