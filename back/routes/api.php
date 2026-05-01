<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/user-auth', [AuthController::class, 'me']);

    // Admin routes
    Route::middleware('admin')->prefix('admin')->group(function () {
        // Usuarios
        Route::get('/users', [AdminController::class, 'indexUsers']);
        Route::get('/users/{user}', [AdminController::class, 'showUser']);
        Route::put('/users/{user}', [AdminController::class, 'updateUser']);
        Route::delete('/users/{user}', [AdminController::class, 'destroyUser']);
        Route::post('/users/{user}/toggle', [AdminController::class, 'toggleUser']);

        // Sesiones
        Route::get('/sessions', [AdminController::class, 'indexSessions']);
        Route::get('/users/{user}/sessions', [AdminController::class, 'userSessions']);
        Route::delete('/sessions/{session}', [AdminController::class, 'destroySession']);
        Route::delete('/users/{user}/sessions', [AdminController::class, 'destroyUserSessions']);

        // Clientes SSO
        Route::get('/clients', [AdminController::class, 'indexClients']);
        Route::post('/clients', [AdminController::class, 'storeClient']);
        Route::put('/clients/{client}', [AdminController::class, 'updateClient']);
        Route::delete('/clients/{client}', [AdminController::class, 'destroyClient']);

        // Roles
        Route::get('/roles', [AdminController::class, 'indexRoles']);
        Route::post('/roles', [AdminController::class, 'storeRole']);
        Route::put('/roles/{role}', [AdminController::class, 'updateRole']);
        Route::delete('/roles/{role}', [AdminController::class, 'destroyRole']);

        // Roles de usuario
        Route::get('/users/{user}/roles', [AdminController::class, 'indexUserRoles']);
        Route::put('/users/{user}/roles', [AdminController::class, 'updateUserRoles']);
    });
});
