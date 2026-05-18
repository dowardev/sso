<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OAuth2Controller;
use App\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('client.origin')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/oauth/token', [OAuth2Controller::class, 'token']);
});

Route::get('/.well-known/jwks', [AuthController::class, 'jwks']);
Route::get('/session/check', [AuthController::class, 'checkSession']);
Route::post('/session/check', [AuthController::class, 'checkSession']);
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/roles', [RoleController::class, 'index']);

Route::middleware('jwt.auth')->group(function () {
    Route::post('/oauth/authorize', [OAuth2Controller::class, 'authorize']);

    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/user-auth', [AuthController::class, 'me']);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/users', [AdminController::class, 'indexUsers']);
        Route::get('/users/{user}', [AdminController::class, 'showUser']);
        Route::put('/users/{user}', [AdminController::class, 'updateUser']);
        Route::delete('/users/{user}', [AdminController::class, 'destroyUser']);
        Route::post('/users/{user}/toggle', [AdminController::class, 'toggleUser']);

        Route::get('/sessions', [AdminController::class, 'indexSessions']);
        Route::get('/users/{user}/sessions', [AdminController::class, 'userSessions']);
        Route::delete('/sessions/{session}', [AdminController::class, 'destroySession']);
        Route::delete('/users/{user}/sessions', [AdminController::class, 'destroyUserSessions']);

        Route::get('/clients', [AdminController::class, 'indexClients']);
        Route::post('/clients', [AdminController::class, 'storeClient']);
        Route::put('/clients/{client}', [AdminController::class, 'updateClient']);
        Route::delete('/clients/{client}', [AdminController::class, 'destroyClient']);

        Route::get('/roles', [AdminController::class, 'indexRoles']);
        Route::post('/roles', [AdminController::class, 'storeRole']);
        Route::put('/roles/{role}', [AdminController::class, 'updateRole']);
        Route::delete('/roles/{role}', [AdminController::class, 'destroyRole']);

        Route::get('/users/{user}/roles', [AdminController::class, 'indexUserRoles']);
        Route::put('/users/{user}/roles', [AdminController::class, 'updateUserRoles']);
    });
});