<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Http\Requests\SetupAdminRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class SetupController extends Controller
{
    public function status(): JsonResponse
    {
        $hasUsers = User::exists();

        return response()->json([
            'setup_completed' => $hasUsers,
        ]);
    }

    public function storeAdmin(SetupAdminRequest $request): JsonResponse
    {
        if (User::exists()) {
            return response()->json(
                ApiError::topLevel(ApiError::SETUP_ALREADY_COMPLETED, 'There are already registered users in the system.'),
                403
            );
        }

        $user = User::create([
            'name' => $request->name,
            'user' => $request->user,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'email_verified_at' => now(),
        ]);

        // Ensure admin role exists (run RoleSeeder logic inline if needed)
        $adminRole = Role::where('slug', 'admin')->first();
        if (! $adminRole) {
            $adminRole = Role::create([
                'name' => 'Administrador',
                'slug' => 'admin',
                'description' => 'Acceso completo al sistema',
                'is_protected' => true,
            ]);
        }

        $user->roles()->attach($adminRole->id, ['sso_client_id' => null]);

        return response()->json(ApiError::success(ApiError::SUCCESS_ADMIN_CREATED, 'Administrator created successfully.') + [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'user' => $user->user,
                'email' => $user->email,
            ],
        ], 201);
    }
}
