<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\UserActivityLog;
use App\Services\ActivityLogService;
use App\Services\AuthService;
use App\Services\EmailVerificationService;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private AuthService $authService;

    private SessionService $sessionService;

    private ActivityLogService $activityLogService;

    private EmailVerificationService $emailVerificationService;

    public function __construct(AuthService $authService, SessionService $sessionService, ActivityLogService $activityLogService, EmailVerificationService $emailVerificationService)
    {
        $this->authService = $authService;
        $this->sessionService = $sessionService;
        $this->activityLogService = $activityLogService;
        $this->emailVerificationService = $emailVerificationService;
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());
        $user = $result['user'];

        $this->emailVerificationService->createForUser($user);

        // Load roles so they're included in the JSON response
        $user->load('roles');

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_REGISTER,
            'Registro de cuenta',
            ['name' => $user->name, 'email' => $user->email],
            $request,
        );

        return response()->json([
            'message' => 'Revisa tu email',
            'user' => $user,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $loginField = $request->email ?? $request->user;

        if (! $loginField) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => ['email' => ['El email o usuario es requerido']],
            ], 422);
        }

        $user = $this->authService->login($loginField, $request->password);

        if (! $user) {
            return response()->json([
                'message' => 'Credenciales inválidas',
            ], 401);
        }

        if ($user->email_verified_at === null) {
            return response()->json([
                'code' => 'EMAIL_NOT_VERIFIED',
                'message' => 'Verifica tu email antes de iniciar sesión.',
                'email' => $user->email,
            ], 403);
        }

        $user->load('roles');

        $dpopPublicKey = $request->input('dpop_public_key');
        $rememberMe = $request->boolean('remember_me');

        $tokenData = $this->authService->generateTokens($user, $request, $rememberMe, $dpopPublicKey);

        $this->authService->registerUserSession($user, $tokenData['token'], $request, $dpopPublicKey, $rememberMe);

        $expirationMinutes = config('jwt.token_expiration', 3600) / 60;
        $responseData = [
            'user' => $user,
            'token' => $tokenData['token'],
            'token_type' => $tokenData['token_type'],
            'expires_in' => $tokenData['expires_in'],
        ];

        $response = response()->json($responseData);

        if (isset($tokenData['refresh_token'])) {
            $refreshExpirationDays = config('jwt.refresh_expiration', 86400) / 86400;
            $minutes = $rememberMe ? $refreshExpirationDays * 1440 : 0;
            $response->withCookie(cookie(
                'refresh_token',
                $tokenData['refresh_token'],
                $minutes,
                '/',
                null,
                config('session.secure', false),
                true,
                false,
                'Lax'
            ));
        }

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_LOGIN,
            'Inicio de sesión',
            ['login_method' => 'password', 'remember_me' => $rememberMe],
            $request,
        );

        return $response;
    }

    public function refreshToken(Request $request): JsonResponse
    {
        $plainRefreshToken = $request->get('refresh_token_value');

        if (! $plainRefreshToken) {
            return response()->json([
                'message' => 'Refresh token no proporcionado',
            ], 401);
        }

        $result = $this->authService->refreshAccessToken($plainRefreshToken, $request);

        if (! $result) {
            return response()->json([
                'message' => 'Refresh token inválido, expirado o revocado',
            ], 401)->withCookie(Cookie::forget('refresh_token', '/', null, true, true));
        }

        $refreshExpirationDays = config('jwt.refresh_expiration', 86400) / 86400;
        $response = response()->json($result);

        $minutes = ($result['remember_me'] ?? false) ? $refreshExpirationDays * 1440 : 0;

        $response->withCookie(cookie(
            'refresh_token',
            $result['refresh_token'],
            $minutes,
            '/',
            null,
            config('session.secure', false),
            true,
            false,
            'Lax'
        ));

        return $response;
    }

    public function logout(Request $request): JsonResponse
    {
        $tokenString = $request->bearerToken();

        if (! $tokenString) {
            return response()->json(['message' => 'Token no proporcionado'], 401);
        }

        // Revoke refresh token sent in body (OAuth client flow)
        $bodyRefreshToken = $request->input('refreshToken') ?? $request->input('refresh_token');
        if ($bodyRefreshToken) {
            $this->authService->revokeRefreshToken($bodyRefreshToken);
        }

        // Revoke cookie refresh token (SSO web flow)
        $refreshTokenCookie = $request->cookie('refresh_token');
        if ($refreshTokenCookie) {
            $this->authService->revokeRefreshToken($refreshTokenCookie);
        }

        $user = $this->authService->getUserFromToken($tokenString);

        if ($user) {
            // Revoke every refresh token and session so OAuth clients and other
            // devices detect the logout instead of relying on the access token TTL.
            $this->authService->revokeAllUserRefreshTokens($user);
            $this->sessionService->revokeAllUserSessions($user);
        }

        try {
            $this->sessionService->revokeSessionByToken($tokenString);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al cerrar sesión: '.$e->getMessage(),
            ], 500);
        }

        if ($user) {
            $this->activityLogService->logFromRequest(
                $user,
                UserActivityLog::ACTION_LOGOUT,
                'Cierre de sesión',
                null,
                $request,
            );
        }

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
        ])
            ->withCookie(Cookie::forget('refresh_token', '/', null, true, true));
    }

    public function me(Request $request): JsonResponse
    {
        $ssoUserId = $request->get('sso_user_id');
        $user = $this->authService->getUserById($ssoUserId);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'user' => $user->user,
            'email' => $user->email,
            'roles' => $user->roles->map(fn ($role) => [
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

    public function getUserProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $user->load('roles');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'user' => $user->user,
            'email' => $user->email,
            'is_admin' => $user->is_admin ?? false,
            'created_at' => $user->created_at,
            'sessions_count' => $user->sessions_count ?? $user->sessions()->count(),
            'tokens_count' => $user->tokens_count ?? $user->tokens()->count(),
            'roles' => $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'is_protected' => $role->is_protected,
            ]),
        ]);
    }

    public function updateUserProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $validated = $request->validated();

        $changedFields = array_keys(array_intersect_key($validated, $user->getOriginal()));
        $oldValues = [];
        foreach ($changedFields as $field) {
            $oldValues[$field] = $user->getOriginal($field);
        }

        $user->update($validated);

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_PROFILE_UPDATE,
            'Actualización de perfil',
            ['changed_fields' => $changedFields, 'old_values' => $oldValues],
            $request,
        );

        return response()->json([
            'message' => 'Perfil actualizado correctamente',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'user' => $user->user,
                'email' => $user->email,
                'is_admin' => $user->is_admin ?? false,
                'created_at' => $user->created_at,
            ],
        ]);
    }

    public function updateUserPassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $validated = $request->validated();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Contraseña actual incorrecta',
                'errors' => ['current_password' => ['La contraseña actual es incorrecta']],
            ], 422);
        }

        $user->password = Hash::make($validated['new_password']);
        $user->save();

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_PASSWORD_CHANGE,
            'Cambio de contraseña',
            null,
            $request,
        );

        return response()->json([
            'message' => 'Contraseña actualizada correctamente',
        ]);
    }

    public function getUserSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $perPage = min((int) $request->get('per_page', 20), 50);
        $sessions = $user->sessions()->with(['token', 'client'])->latest('last_used_at')->paginate($perPage);

        return response()->json([
            'data' => $sessions->map(function ($session) {
                return [
                    'id' => $session->id,
                    'client' => $session->client ? [
                        'id' => $session->client->id,
                        'name' => $session->client->name,
                        'client_id' => $session->client->client_id,
                    ] : null,
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'last_used_at' => $session->last_used_at,
                    'created_at' => $session->created_at,
                    'token_active' => $session->token ? ! $session->token->isExpired() : false,
                ];
            }),
            'pagination' => [
                'total' => $sessions->total(),
                'per_page' => $sessions->perPage(),
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
            ],
        ]);
    }

    public function revokeUserSession(Request $request, int $sessionId): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $session = $user->sessions()->find($sessionId);
        if (! $session) {
            return response()->json(['message' => 'Sesión no encontrada'], 404);
        }

        if ($session->token) {
            $session->token->delete();
        }
        $session->delete();

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_SESSION_REVOKED,
            'Sesión revocada',
            ['session_id' => $sessionId, 'ip' => $session->ip_address],
            $request,
        );

        return response()->json([
            'message' => 'Sesión revocada correctamente',
        ]);
    }

    public function revokeAllUserSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $user->sessions()->with('token')->lazy()->each(function ($session) {
            if ($session->token) {
                $session->token->delete();
            }
        });
        $user->sessions()->delete();

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_SESSION_REVOKED,
            'Todas las sesiones revocadas',
            null,
            $request,
        );

        return response()->json([
            'message' => 'Todas las sesiones han sido revocadas correctamente',
        ]);
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $validated = $request->validate([
            'password' => 'required|string',
            'confirmation' => 'required|string|in:ELIMINAR',
        ]);

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['La contraseña es incorrecta'],
            ]);
        }

        // Revoke all tokens
        $user->tokens()->delete();

        // Revoke all sessions
        $user->sessions()->delete();

        // Revoke all OAuth authorizations
        $user->oauthClientAuthorizations()->delete();

        // Delete activity logs
        $user->activityLogs()->delete();

        // Log before deleting (last activity record)
        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_ACCOUNT_DELETED,
            'Cuenta eliminada',
            null,
            $request,
        );

        // Hard delete the user
        $user->delete();

        return response()->json([
            'message' => 'Cuenta eliminada correctamente',
        ]);
    }

    public function getUserActivityLog(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $logs = $user->activityLogs()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $data = $logs->map(function ($log) {
            return [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'created_at' => $log->created_at,
                'metadata' => $log->metadata ?? [],
            ];
        });

        return response()->json([
            'data' => $data,
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
        ]);
    }
}
