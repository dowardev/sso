<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\ActivityLogService;
use App\Services\AuthService;
use App\Services\EmailVerificationService;
use App\Services\PasswordResetService;
use App\Services\PasskeyService;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

class AuthController extends Controller
{
    private AuthService $authService;

    private SessionService $sessionService;

    private ActivityLogService $activityLogService;

    private EmailVerificationService $emailVerificationService;

    private PasswordResetService $passwordResetService;

    private PasskeyService $passkeyService;

    public function __construct(AuthService $authService, SessionService $sessionService, ActivityLogService $activityLogService, EmailVerificationService $emailVerificationService, PasswordResetService $passwordResetService, PasskeyService $passkeyService)
    {
        $this->authService = $authService;
        $this->sessionService = $sessionService;
        $this->activityLogService = $activityLogService;
        $this->emailVerificationService = $emailVerificationService;
        $this->passwordResetService = $passwordResetService;
        $this->passkeyService = $passkeyService;
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

    /**
     * Return crypto blobs (salts, wrapped DEK, encrypted profile) for the
     * given login_id. Public material — decryption happens client-side.
     * Consumed by advanced login, recovery and profile refresh flows.
     */
    public function cryptoBlobs(Request $request): JsonResponse
    {
        $request->validate([
            'login_id' => 'required|string',
        ]);

        $blobs = $this->authService->getCryptoBlobs($request->login_id);

        if (! $blobs) {
            return response()->json([
                'message' => 'Usuario no encontrado',
            ], 404);
        }

        return response()->json($blobs);
    }

    /**
     * Advanced login - Step 2: Verify auth_proof and issue token
     */
    public function loginAdvancedVerify(Request $request): JsonResponse
    {
        $request->validate([
            'login_id' => 'required|string',
            'auth_proof' => 'required|string',
            'client_id' => 'nullable|string',
            'remember_me' => 'nullable|boolean',
            'dpop_public_key' => 'nullable|string',
        ]);

        $user = $this->authService->verifyAuthProof($request->login_id, $request->auth_proof);

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

        $responseData = [
            'user' => $user,
            'token' => $tokenData['token'],
            'token_type' => $tokenData['token_type'],
            'expires_in' => $tokenData['expires_in'],
        ];

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_LOGIN,
            'Inicio de sesión (cifrado avanzado)',
            ['login_method' => 'password_advanced', 'remember_me' => $rememberMe],
            $request,
        );

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

        return $response;
    }

    /**
     * Advanced registration with zero-knowledge encryption
     */
    public function registerAdvanced(Request $request): JsonResponse
    {
        $request->validate([
            'login_id' => 'required|string',
            'auth_proof' => 'required|string',
            'salt_a' => 'required|string',
            'salt_r' => 'required|string',
            'wrapped_dek_a' => 'required|string',
            'wrapped_dek_r' => 'required|string',
            'encrypted_profile' => 'required|string',
            'encrypted_seed_verification' => 'required|string',
            'encryption_level' => 'required|in:advanced',
            'passkey_public_key' => 'nullable|string',
            'passkey_credential_id' => 'nullable|string',
            'client_id' => 'nullable|string',
        ]);

        // Hash the auth_proof with Argon2id for storage
        $authHash = Hash::make($request->auth_proof);

        $result = $this->authService->registerAdvanced([
            'login_id' => $request->login_id,
            'auth_hash' => $authHash,
            'salt_a' => $request->salt_a,
            'salt_r' => $request->salt_r,
            'wrapped_dek_a' => $request->wrapped_dek_a,
            'wrapped_dek_r' => $request->wrapped_dek_r,
            'encrypted_profile' => $request->encrypted_profile,
            'encrypted_seed_verification' => $request->encrypted_seed_verification,
            'passkey_public_key' => $request->passkey_public_key,
            'passkey_credential_id' => $request->passkey_credential_id,
        ]);

        $user = $result['user'];

        $this->emailVerificationService->createForUser($user);

        $user->load('roles');

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_REGISTER,
            'Registro de cuenta (cifrado avanzado)',
            ['encryption_level' => 'advanced'],
            $request,
        );

        return response()->json([
            'message' => 'Revisa tu email',
            'user' => $user,
        ], 201);
    }

    /**
     * Recovery - Step 1: Verify seed phrase (client-side verification)
     *
     * Client derives Key_R from seed_phrase + salt_r, computes
     * verification = HMAC(Key_R, "verify"), compares with stored
     * encrypted_seed_verification. If match, proceeds to step 2.
     */
    public function recoveryVerify(Request $request): JsonResponse
    {
        $request->validate([
            'login_id' => 'required|string',
            'seed_verification' => 'required|string', // HMAC(Key_R, "verify")
        ]);

        $user = $this->authService->recoveryVerify($request->login_id, '');

        if (! $user) {
            return response()->json([
                'message' => 'Usuario no encontrado',
            ], 404);
        }

        // Verify the client's computed verification matches stored value
        if (! hash_equals($user->encrypted_seed_verification, $request->seed_verification)) {
            return response()->json([
                'message' => 'Frase semilla incorrecta',
            ], 401);
        }

        // Return crypto blobs needed for recovery (salt_r, wrapped_dek_r)
        return response()->json([
            'profile_version' => $user->profile_version,
            'salt_r' => $user->salt_r,
            'wrapped_dek_r' => $user->wrapped_dek_r,
            'encrypted_profile' => $user->encrypted_profile,
        ]);
    }

    /**
     * Recovery - Step 2: Complete recovery with new password.
     *
     * Client has verified seed phrase, derived Key_R, unwrapped DEK,
     * generated new Key_A from new password, wrapped DEK with new Key_A,
     * re-encrypted profile, computed new auth_proof.
     */
    public function recoveryComplete(Request $request): JsonResponse
    {
        $request->validate([
            'login_id' => 'required|string',
            'auth_proof' => 'required|string', // HMAC(new_Key_A, "auth")
            'salt_a' => 'required|string',
            'wrapped_dek_a' => 'required|string',
            'wrapped_dek_r' => 'required|string',
            'encrypted_profile' => 'required|string',
        ]);

        // Hash the new auth_proof for storage
        $authHash = Hash::make($request->auth_proof);

        try {
            $result = $this->authService->recoveryComplete([
                'login_id' => $request->login_id,
                'auth_hash' => $authHash,
                'salt_a' => $request->salt_a,
                'wrapped_dek_a' => $request->wrapped_dek_a,
                'wrapped_dek_r' => $request->wrapped_dek_r,
                'encrypted_profile' => $request->encrypted_profile,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 404);
        }

        $user = $result['user'];
        $user->load('roles');

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_PASSWORD_CHANGE,
            'Recuperación de cuenta (cifrado avanzado)',
            ['recovery' => true],
            $request,
        );

        return response()->json([
            'message' => 'Cuenta recuperada correctamente. Inicia sesión con tu nueva contraseña.',
            'user' => $user,
        ]);
    }

    /**
     * Change password for advanced user (DEK rotation, same seed).
     *
     * Different from recovery: keeps same DEK, only re-wraps with new Key_A.
     * Client sends new salt_a, new wrapped_dek_a, new auth_proof.
     */
    public function changePasswordAdvanced(Request $request): JsonResponse
    {
        $request->validate([
            'login_id' => 'required|string',
            'auth_proof' => 'required|string',
            'salt_a' => 'required|string',
            'wrapped_dek_a' => 'required|string',
        ]);

        $authHash = Hash::make($request->auth_proof);

        try {
            $result = $this->authService->changePasswordAdvanced([
                'login_id' => $request->login_id,
                'auth_hash' => $authHash,
                'salt_a' => $request->salt_a,
                'wrapped_dek_a' => $request->wrapped_dek_a,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 404);
        }

        $user = $result['user'];
        $user->load('roles');

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_PASSWORD_CHANGE,
            'Cambio de contraseña (cifrado avanzado)',
            ['dek_rotated' => false],
            $request,
        );

        return response()->json([
            'message' => 'Contraseña actualizada correctamente. Todas las sesiones han sido revocadas.',
            'user' => $user,
        ]);
    }


        /**
         * Upgrade basic user to advanced encryption (zero-knowledge).
         *
         * Client provides current password, generates all crypto material,
         * encrypts current profile, and sends blobs to server.
         * Server stores blobs, sets encryption_level='advanced', nullifies name/user/email.
         */
        public function upgradeToAdvanced(Request $request): JsonResponse
        {
            $user = $request->user();
            if (! $user) {
                return response()->json(['message' => 'No autenticado'], 401);
            }

            $request->validate([
                'current_password' => 'required|string',
                'auth_proof' => 'required|string',
                'login_id' => 'required|string',
                'salt_a' => 'required|string',
                'salt_r' => 'required|string',
                'wrapped_dek_a' => 'required|string',
                'wrapped_dek_r' => 'required|string',
                'encrypted_profile' => 'required|string',
                'encrypted_seed_verification' => 'required|string',
                'passkey_public_key' => 'nullable|string',
                'passkey_credential_id' => 'nullable|string',
            ]);

            $authHash = Hash::make($request->auth_proof);

            try {
                $result = $this->authService->upgradeToAdvanced($user, [
                    'current_password' => $request->current_password,
                    'auth_hash' => $authHash,
                    'login_id' => $request->login_id,
                    'salt_a' => $request->salt_a,
                    'salt_r' => $request->salt_r,
                    'wrapped_dek_a' => $request->wrapped_dek_a,
                    'wrapped_dek_r' => $request->wrapped_dek_r,
                    'encrypted_profile' => $request->encrypted_profile,
                    'encrypted_seed_verification' => $request->encrypted_seed_verification,
                    'passkey_public_key' => $request->passkey_public_key,
                    'passkey_credential_id' => $request->passkey_credential_id,
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }

            $user = $result['user'];
            $user->load('roles');

            $this->activityLogService->logFromRequest(
                $user,
                UserActivityLog::ACTION_PASSWORD_CHANGE,
                'Actualización a cifrado avanzado',
                ['encryption_level' => 'advanced'],
                $request,
            );

            return response()->json([
                'message' => 'Cuenta actualizada a cifrado avanzado. Inicia sesión con tu nueva contraseña.',
                'user' => $user,
                'seed_phrase' => $request->input('seed_phrase'), // Client should show this to user
            ]);
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

        $baseResponse = [
            'id' => $user->id,
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
            'encryption_level' => $user->encryption_level,
            'profile_version' => $user->profile_version,
        ];

        // For advanced users, return encrypted blobs instead of plaintext
        if ($user->encryption_level === 'advanced') {
            return response()->json(array_merge($baseResponse, [
                'name' => null,
                'user' => null,
                'email' => null,
                'login_id' => $user->login_id,
                'salt_a' => $user->salt_a,
                'salt_r' => $user->salt_r,
                'wrapped_dek_a' => $user->wrapped_dek_a,
                'encrypted_profile' => $user->encrypted_profile,
            ]));
        }

        // Basic users: return plaintext profile
        return response()->json(array_merge($baseResponse, [
            'name' => $user->name,
            'user' => $user->user,
            'email' => $user->email,
            'login_id' => null,
        ]));
    }

    public function updateUserProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $validated = $request->validated();

        // Handle advanced encryption: client sends encrypted_profile
        if ($user->encryption_level === 'advanced') {
            if (! isset($validated['encrypted_profile'])) {
                return response()->json([
                    'message' => 'encrypted_profile requerido para usuarios avanzados',
                ], 422);
            }

            $oldEncryptedProfile = $user->encrypted_profile;
            $user->encrypted_profile = $validated['encrypted_profile'];
            $user->profile_version++;
            $user->save();

            $this->activityLogService->logFromRequest(
                $user,
                UserActivityLog::ACTION_PROFILE_UPDATE,
                'Actualización de perfil cifrado',
                ['changed_fields' => ['encrypted_profile']],
                $request,
            );

            return response()->json([
                'message' => 'Perfil actualizado correctamente',
                'user' => [
                    'id' => $user->id,
                    'name' => null,
                    'user' => null,
                    'email' => null,
                    'is_admin' => $user->is_admin ?? false,
                    'created_at' => $user->created_at,
                    'encryption_level' => 'advanced',
                    'login_id' => $user->login_id,
                    'salt_a' => $user->salt_a,
                    'wrapped_dek_a' => $user->wrapped_dek_a,
                    'encrypted_profile' => $user->encrypted_profile,
                ],
            ]);
        }

        // Basic users: standard update
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
                'encryption_level' => 'basic',
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

        // Revoke all sessions and refresh tokens (security requirement)
        $this->authService->revokeAllUserRefreshTokens($user);
        $this->sessionService->revokeAllUserSessions($user);

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_PASSWORD_CHANGE,
            'Cambio de contraseña',
            ['sessions_revoked' => true, 'tokens_revoked' => true],
            $request,
        );

        return response()->json([
            'message' => 'Contraseña actualizada correctamente. Todas las sesiones han sido revocadas.',
        ]);
    }

    // ─── Passkey / WebAuthn Endpoints ────────────────────────────────────────

    /**
     * Start passkey registration: returns PublicKeyCredentialCreationOptions
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'identifier' => 'required|string',
        ]);

        $this->passwordResetService->sendResetLink($request->input('identifier'));

        return response()->json([
            'message' => 'Si la cuenta existe, te enviamos instrucciones.',
        ]);
    }

    /**
     * Reset a password using a token from the email link.
     *
     * Returns 422 (never 404/401) for an invalid or expired token so the
     * response does not distinguish between "wrong token" and "no such user".
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $this->passwordResetService->reset(
            $request->input('token'),
            $request->input('password'),
        );

        if (! $user) {
            return response()->json([
                'message' => 'Token inválido o expirado.',
            ], 422);
        }

        return response()->json([
            'message' => 'Contraseña actualizada.',
        ]);
    }

    public function passkeyRegisterStart(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $user = User::find($request->user_id);
        if (! $user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        // Get existing credentials to exclude (multi-passkey)
        $existingCredentials = $this->passkeyService->getCredentialIds($user);

        $options = $this->passkeyService->generateRegistrationOptions($user, $existingCredentials);
        $optionsJson = $this->passkeyService->serializeOptions($options);

        // Store the full options (incl. challenge) so finish() can rehydrate
        // the exact challenge the client signs.
        $request->session()->put('passkey_registration_options', $optionsJson);
        $request->session()->put('passkey_registration_user_id', $user->id);

        return response()->json($optionsJson);
    }

    /**
     * Complete passkey registration: verify attestation and store credential
     */
    public function passkeyRegisterFinish(Request $request): JsonResponse
    {
        $optionsData = $request->session()->pull('passkey_registration_options');
        $userId = $request->session()->pull('passkey_registration_user_id');

        if (! $optionsData || ! $userId) {
            return response()->json(['message' => 'Sesión de registro expirada'], 400);
        }

        $user = User::find($userId);
        if (! $user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        // Rehydrate the creation options (challenge included) from stored JSON
        $options = $this->passkeyService->deserializeOptions(
            $optionsData,
            PublicKeyCredentialCreationOptions::class
        );

        try {
            $credentialRecord = $this->passkeyService->verifyRegistrationResponse($options, $request->all(), $request->getHttpHost());
            $this->passkeyService->storeCredential($user, $credentialRecord);

            $this->activityLogService->logFromRequest(
                $user,
                UserActivityLog::ACTION_PASSWORD_CHANGE,
                'Passkey registrado',
                ['credential_id' => base64_encode($credentialRecord->publicKeyCredentialId)],
                $request,
            );

            return response()->json([
                'message' => 'Passkey registrado correctamente',
                'credential_id' => base64_encode($credentialRecord->publicKeyCredentialId),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al registrar passkey: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Start passkey authentication: returns PublicKeyCredentialRequestOptions
     */
    public function passkeyAuthenticateStart(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'login_id' => 'nullable|string',
        ]);

        $user = null;
        if ($request->has('user_id')) {
            $user = User::find($request->user_id);
        } elseif ($request->has('login_id')) {
            $user = User::where('login_id', $request->login_id)->where('encryption_level', 'advanced')->first();
        }

        if (! $user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        // Get stored credential records
        $records = $this->passkeyService->getUserCredentialRecords($user);
        if (empty($records)) {
            return response()->json(['message' => 'Usuario no tiene passkeys registrados'], 400);
        }

        $options = $this->passkeyService->generateAuthenticationOptions(
            array_map(fn ($r) => base64_encode($r->publicKeyCredentialId), $records)
        );
        $optionsJson = $this->passkeyService->serializeOptions($options);

        $request->session()->put('passkey_auth_options', $optionsJson);
        $request->session()->put('passkey_auth_user_id', $user->id);

        return response()->json($optionsJson);
    }

    /**
     * Complete passkey authentication: verify assertion and issue token
     */
    public function passkeyAuthenticateFinish(Request $request): JsonResponse
    {
        $optionsData = $request->session()->pull('passkey_auth_options');
        $userId = $request->session()->pull('passkey_auth_user_id');

        if (! $optionsData || ! $userId) {
            return response()->json(['message' => 'Sesión de autenticación expirada'], 400);
        }

        $user = User::find($userId);
        if (! $user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        $records = $this->passkeyService->getUserCredentialRecords($user);
        if (empty($records)) {
            return response()->json(['message' => 'Usuario no tiene passkeys registrados'], 400);
        }

        $options = $this->passkeyService->deserializeOptions(
            $optionsData,
            PublicKeyCredentialRequestOptions::class
        );

        // Find matching credential record by credential ID from request
        $credentialId = $request->input('id');
        $matchedRecord = null;
        foreach ($records as $record) {
            if (base64_encode($record->publicKeyCredentialId) === $credentialId) {
                $matchedRecord = $record;
                break;
            }
        }

        if (! $matchedRecord) {
            return response()->json(['message' => 'Credencial no encontrada'], 400);
        }

        try {
            $userHandle = $user->user ?? (string) $user->id;
            $verifiedRecord = $this->passkeyService->verifyAuthenticationResponse(
                $options,
                $request->all(),
                $matchedRecord,
                $request->getHttpHost(),
                $userHandle
            );

                // Check email verification for advanced users
                if ($user->encryption_level === 'advanced' && $user->email_verified_at === null) {
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

                $responseData = [
                    'user' => $user,
                    'token' => $tokenData['token'],
                    'token_type' => $tokenData['token_type'],
                    'expires_in' => $tokenData['expires_in'],
                ];

                $this->activityLogService->logFromRequest(
                    $user,
                    UserActivityLog::ACTION_LOGIN,
                    'Inicio de sesión (passkey)',
                    ['login_method' => 'passkey'],
                    $request,
                );

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

                return $response;
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Error en autenticación passkey: ' . $e->getMessage(),
                ], 400);
            }
        }

    /**
     * Delete user's passkey
     */
    public function passkeyDelete(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $request->validate([
            'credential_id' => 'required|string',
        ]);

        $owned = $this->passkeyService->getCredentialIds($user);
        if (! in_array($request->credential_id, $owned, true)) {
            return response()->json(['message' => 'Credencial no encontrada'], 404);
        }

        $this->passkeyService->removeCredential($user, $request->credential_id);

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_PASSWORD_CHANGE,
            'Passkey eliminado',
            null,
            $request,
        );

        return response()->json(['message' => 'Passkey eliminado correctamente']);
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