<?php

namespace App\Http\Controllers;

use App\Models\SsoClient;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'user' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'client_id' => 'required|string|exists:sso_clients,client_id',
        ], [
            'client_id.exists' => 'La aplicación ":value" no está registrada en el SSO. '
                . 'Por favor, contacta al administrador del sistema.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'user' => $request->user,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        $this->registerSession($user, $token, $request);

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'nullable|string',
            'user' => 'nullable|string',
            'password' => 'required|string',
            'client_id' => 'nullable|string|exists:sso_clients,client_id',
        ], [
            'client_id.exists' => 'La aplicación ":value" no está registrada en el SSO. '
                . 'Por favor, contacta al administrador del sistema.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $loginField = $request->email ?? $request->user;
        
        if (!$loginField) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => ['email' => ['El email o usuario es requerido']],
            ], 422);
        }

        $user = User::where('email', $loginField)
            ->orWhere('user', $loginField)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Credenciales inválidas',
            ], 401);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        $this->registerSession($user, $token, $request);

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        UserSession::where('token_id', $token->id)->update([
            'last_used_at' => now(),
        ]);

        $token->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $user->load('roles');
        
        return response()->json($user);
    }

    private function registerSession(User $user, string $plainTextToken, Request $request)
    {
        $client = null;
        
        if ($request->client_id) {
            $client = SsoClient::where('client_id', $request->client_id)->first();
        }

        $tokenId = explode('|', $plainTextToken)[0];

        UserSession::create([
            'user_id' => $user->id,
            'sso_client_id' => $client?->id,
            'token_id' => $tokenId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_used_at' => now(),
        ]);
    }
}
