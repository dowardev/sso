<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RefreshTokenRequest;
use App\Http\Requests\RegisterRequest;
use App\Services\AuthService;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class AuthController extends Controller
{
    private AuthService $authService;
    private SessionService $sessionService;

    public function __construct(AuthService $authService, SessionService $sessionService)
    {
        $this->authService = $authService;
        $this->sessionService = $sessionService;
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());
        $user = $result['user'];

        $dpopPublicKey = $request->input('dpop_public_key');
        $tokenData = $this->authService->generateAuthToken($user, $dpopPublicKey, false);

        $this->authService->registerUserSession($user, $tokenData['token'], $request, $dpopPublicKey, false);

        return response()->json([
            'user' => $user,
            'token' => $tokenData['token'],
            'token_type' => $tokenData['token_type'],
            'expires_in' => $tokenData['expires_in'],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $loginField = $request->email ?? $request->user;

        if (!$loginField) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => ['email' => ['El email o usuario es requerido']],
            ], 422);
        }

        $user = $this->authService->login($loginField, $request->password);

        if (!$user) {
            return response()->json([
                'message' => 'Credenciales inválidas',
            ], 401);
        }

        $dpopPublicKey = $request->input('dpop_public_key');
        $rememberMe = $request->boolean('remember_me');
        $tokenData = $this->authService->generateAuthToken($user, $dpopPublicKey, $rememberMe);

        $this->authService->registerUserSession($user, $tokenData['token'], $request, $dpopPublicKey, $rememberMe);

        $expirationMinutes = config('jwt.token_expiration', 3600) / 60;
        $response = response()->json([
            'user' => $user,
            'token' => $tokenData['token'],
            'token_type' => $tokenData['token_type'],
            'expires_in' => $tokenData['expires_in'],
        ]);

        $response->withCookie(cookie('sso_token', $tokenData['token'], $expirationMinutes, '/', null, false, false));

        return $response;
    }

    public function refreshToken(RefreshTokenRequest $request): JsonResponse
    {
        $result = $this->authService->refreshToken(
            $request->input('token'),
            $request->input('client_id')
        );

        if (!$result) {
            return response()->json([
                'message' => 'Token inválido o expirado',
            ], 401);
        }

        return response()->json($result);
    }

    public function logout(Request $request): JsonResponse
    {
        $tokenString = $request->bearerToken();

        if (!$tokenString) {
            $tokenString = $request->cookie('sso_token');
        }

        if (!$tokenString) {
            return response()->json(['message' => 'Token no proporcionado'], 401);
        }

        try {
            $this->sessionService->revokeSessionByToken($tokenString);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al cerrar sesión: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
        ])->withCookie(Cookie::forget('sso_token'));
    }

    public function me(Request $request): JsonResponse
    {
        $ssoUserId = $request->get('sso_user_id');
        $user = $this->authService->getUserById($ssoUserId);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'user' => $user->user,
            'email' => $user->email,
            'roles' => $user->roles->map(fn($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'is_protected' => $role->is_protected,
            ]),
        ]);
    }

    public function jwks(): JsonResponse
    {
        $jwks = $this->authService->getJwks();

        if (isset($jwks['error'])) {
            return response()->json($jwks, 500);
        }

        return response()->json($jwks);
    }

    public function checkSession(Request $request): JsonResponse
    {
        $result = $this->authService->checkSessionFromRequest($request);
        return response()->json($result);
    }
}
