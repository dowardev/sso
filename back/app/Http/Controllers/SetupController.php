<?php

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

        $setupToken = config('app.setup_token');
        if ($setupToken) {
            $providedToken = $request->header('X-Setup-Token');
            if (!$providedToken || !hash_equals($setupToken, $providedToken)) {
                return response()->json([
                    'message' => 'Token de setup inválido.',
                ], 403);
            }
        }

        $user = User::create([
            'name' => $request->name,
            'user' => $request->user,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $adminRole = Role::where('slug', 'admin')->first();
        if ($adminRole) {
            $user->roles()->attach($adminRole->id, ['sso_client_id' => null]);
        }

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