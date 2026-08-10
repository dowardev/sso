<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Http\Requests\SetupAdminRequest;
use App\Models\Role;
use App\Models\User;
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
            return response()->json([
                'message' => 'Ya existen usuarios registrados en el sistema.',
            ], 403);
        }

        $user = User::create([
            'name' => $request->name,
            'user' => $request->user,
            'email' => $request->email,
            'password' => Hash::make($request->password),
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

        return response()->json([
            'message' => 'Administrador creado correctamente.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'user' => $user->user,
                'email' => $user->email,
            ],
        ], 201);
    }
}
