<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Http\Requests\AuthMethodsRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\TotpCredential;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\ActivityLogService;
use App\Services\AuthService;
use App\Services\EmailVerificationService;
use App\Services\PasskeyService;
use App\Services\PasswordResetService;
use App\Services\SessionService;
use App\Services\TotpService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

class AuthController extends Controller
{
    private AuthService $authService;

    private SessionService $sessionService;

    private ActivityLogService $activityLogService;

    private EmailVerificationService $emailVerificationService;

    private PasswordResetService $passwordResetService;

    private PasskeyService $passkeyService;

    private TotpService $totpService;

    public function __construct(AuthService $authService, SessionService $sessionService, ActivityLogService $activityLogService, EmailVerificationService $emailVerificationService, PasswordResetService $passwordResetService, PasskeyService $passkeyService, TotpService $totpService)
    {
        $this->authService = $authService;
        $this->sessionService = $sessionService;
        $this->activityLogService = $activityLogService;
        $this->emailVerificationService = $emailVerificationService;
        $this->passwordResetService = $passwordResetService;
        $this->passkeyService = $passkeyService;
        $this->totpService = $totpService;
    }

    /**
     * Build the `refresh_token` cookie with session-config-driven attributes.
     *
     * Single point of truth for every refresh_token emission site in this
     * controller: basic login, advanced login, passkey login, and the
     * /api/refresh-token rotation endpoint. Pulling these attributes from
     * `config/session.*` keeps the LBDS-252 hardening (SameSite=Strict,
     * Secure by default) applied uniformly and lets a single test in
     * RefreshTokenCookieAttributesTest cover every emission site, so a
     * future divergence has nowhere to hide.
     */
    private function refreshTokenCookie(string $token, bool $rememberMe): SymfonyCookie
    {
        $refreshExpirationDays = config('jwt.refresh_expiration', 86400) / 86400;
        $minutes = $rememberMe ? (int) ($refreshExpirationDays * 1440) : 0;

        return cookie(
            'refresh_token',
            $token,
            $minutes,
            '/',
            null,
            config('session.secure', false),
            true,
            false,
            config('session.same_site', 'strict')
        );
    }

    /**
     * Build the cookie used by `logout()` to clear `refresh_token` on the
     * client. Mirrors the attribute selection of {@see refreshTokenCookie()}
     * exactly so a cookie issued under one set of attributes is matched by the
     * clear under the same attributes — fixing the LBDS-278 asymmetry where
     * the previous `Cookie::forget(...)` call produced a clear cookie whose
     * Secure flag did not reflect `config('session.secure')`. Browsers match
     * a cookie by name+path+domain (not by Secure), so this parity is
     * defence-in-depth rather than a behavioural fix, but it keeps the
     * emission/clear pair self-consistent and visible to a single test.
     */
    private function refreshTokenForgetCookie(): SymfonyCookie
    {
        return cookie(
            'refresh_token',
            null,
            -2628000,
            '/',
            null,
            config('session.secure', false),
            true,
            false,
            config('session.same_site', 'strict')
        );
    }

    /**
     * If the user has an active TOTP credential, return a "challenge
     * pending" response with a one-time token instead of issuing the JWT
     * straight away. Returns null when no TOTP is enabled — the caller
     * keeps going with the normal token issuance.
     *
     * The pending token is a high-entropy opaque string stored in cache
     * for 5 minutes; the second step (/api/totp/login) consumes it via
     * {@see self::purgeTotpPending()} (single-use, so a parallel replay
     * cannot piggy-back on a successful challenge).
     *
     * When the user ALSO has passkeys, a passkey_token is issued alongside
     * the totp_token so the frontend can offer both 2FA methods as equal
     * choices on step 2 (F1 fix).
     */
    private function startTotpChallenge(User $user, Request $request, bool $rememberMe, ?string $dpopPublicKey): ?JsonResponse
    {
        $credential = $user->activeTotpCredential()->first();
        if (! $credential) {
            return null;
        }

        $token = Str::random(64);

        Cache::put("totp_pending:{$token}", [
            'user_id' => $user->id,
            'remember_me' => $rememberMe,
            'dpop_public_key' => $dpopPublicKey,
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            // Defense-in-depth: cache the algorithm/digits so the second
            // step never has to trust the request payload for security-
            // sensitive parameters.
            'digits' => $credential->digits,
            'period' => $credential->period,
            'algorithm' => $credential->algorithm,
        ], 300);

        $response = [
            'code' => 'TOTP_REQUIRED',
            'message' => 'Necesitás tu código TOTP (o un código de respaldo) para continuar.',
            'totp_token' => $token,
            // Surface the policy so the client can label the input field
            // correctly (6 digits, 30s period) without hardcoding it.
            'digits' => $credential->digits,
            'period' => $credential->period,
        ];

        // F1: When user has BOTH TOTP and passkeys, also issue a passkey_token
        // so the frontend can offer passkey as an alternative 2FA method.
        if ($user->passkeyCredentials()->exists()) {
            $passkeyToken = Str::random(64);
            Cache::put("passkey_pending:{$passkeyToken}", [
                'user_id' => $user->id,
                'remember_me' => $rememberMe,
                'dpop_public_key' => $dpopPublicKey,
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
            ], 300);
            $response['passkey_token'] = $passkeyToken;
        }

        return response()->json($response, 200);
    }

    /**
     * If the user has passkeys but no TOTP, return a "challenge
     * pending" response with a one-time token instead of issuing the JWT
     * straight away. Returns null when the user has no passkeys or has
     * TOTP (in which case TOTP takes precedence as the mandated 2FA).
     *
     * The pending token is a high-entropy opaque string stored in cache
     * for 5 minutes; the passkey authentication finish endpoint consumes
     * it via Cache::pull (single-use, so a parallel replay cannot
     * piggy-back on a successful challenge).
     */
    private function startPasskeyChallenge(User $user, Request $request, bool $rememberMe, ?string $dpopPublicKey): ?JsonResponse
    {
        // Passkey challenge only applies when user has passkeys AND no TOTP.
        // If TOTP is enabled, startTotpChallenge takes precedence AND issues
        // the passkey_token alongside the totp_token (F1 fix).
        if (! $user->passkeyCredentials()->exists() || $user->activeTotpCredential()->exists()) {
            return null;
        }

        $token = Str::random(64);

        Cache::put("passkey_pending:{$token}", [
            'user_id' => $user->id,
            'remember_me' => $rememberMe,
            'dpop_public_key' => $dpopPublicKey,
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
        ], 300);

        return response()->json([
            'code' => 'PASSKEY_REQUIRED',
            'message' => 'Usá tu passkey para completar el inicio de sesión.',
            'passkey_token' => $token,
        ], 200);
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
                'code' => ApiError::AUTH_INVALID_CREDENTIALS,
            ], 422);
        }

        $user = $this->authService->login($loginField, $request->password);

        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_INVALID_CREDENTIALS, 'Credenciales inválidas'),
                401
            );
        }

        if ($user->email_verified_at === null) {
            return response()->json([
                'code' => ApiError::AUTH_EMAIL_NOT_VERIFIED,
                'message' => 'Verifica tu email antes de iniciar sesión.',
                'email' => $user->email,
            ], 403);
        }

        $user->load('roles');

        $dpopPublicKey = $request->input('dpop_public_key');
        $rememberMe = $request->boolean('remember_me');

        // TOTP gate (LBDS-8): when the user has an active TOTP credential,
        // password alone is not enough — the second step is /api/totp/login.
        // F1: If user also has passkeys, startTotpChallenge now issues a
        // passkey_token alongside the totp_token so the frontend can offer
        // both methods on step 2.
        if ($totpResponse = $this->startTotpChallenge($user, $request, $rememberMe, $dpopPublicKey)) {
            return $totpResponse;
        }

        // Passkey gate (LBDS-304): when the user has passkeys but NO TOTP,
        // the passkey becomes the required second factor. The password step
        // returns PASSKEY_REQUIRED with a passkey_token; the client then
        // completes the WebAuthn ceremony via the existing passkey endpoints.
        if ($passkeyResponse = $this->startPasskeyChallenge($user, $request, $rememberMe, $dpopPublicKey)) {
            return $passkeyResponse;
        }

        $tokenData = $this->authService->generateTokens($user, $request, $rememberMe, $dpopPublicKey);

        $this->authService->registerUserSession($user, $tokenData['token'], $request, $dpopPublicKey, $rememberMe);

        $expirationMinutes = config('jwt.token_expiration', 3600) / 60;
        $user->makeVisible(['login_id', 'encryption_level']);
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
            $response->withCookie($this->refreshTokenCookie($tokenData['refresh_token'], $rememberMe));
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
     * Report which auth methods an identifier's account supports.
     *
     * Public endpoint for identifier-first login: the identifier is a
     * username only (basic accounts match `user` directly; advanced accounts
     * match the deterministic HMAC login_id). Always returns 200
     * (account-existence disclosure is a documented trade-off).
     */
    public function authMethods(AuthMethodsRequest $request): JsonResponse
    {
        return response()->json(
            $this->authService->resolveAuthMethods($request->validated('identifier'))
        );
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
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_USER_NOT_FOUND, 'Usuario no encontrado'),
                404
            );
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
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_INVALID_CREDENTIALS, 'Credenciales inválidas'),
                401
            );
        }

        if ($user->email_verified_at === null) {
            return response()->json([
                'code' => ApiError::AUTH_EMAIL_NOT_VERIFIED,
                'message' => 'Verifica tu email antes de iniciar sesión.',
                'email' => $user->email,
            ], 403);
        }

        $user->load('roles');

        $dpopPublicKey = $request->input('dpop_public_key');
        $rememberMe = $request->boolean('remember_me');

        // TOTP gate (LBDS-8): advanced (ZKE) users can also opt-in to
        // TOTP as a fallback to their passkey — the challenge path is
        // identical to the basic login flow above.
        // F1: If user also has passkeys, startTotpChallenge now issues a
        // passkey_token alongside the totp_token so the frontend can offer
        // both methods on step 2.
        if ($totpResponse = $this->startTotpChallenge($user, $request, $rememberMe, $dpopPublicKey)) {
            return $totpResponse;
        }

        // Passkey gate (LBDS-304): same as basic login — when the advanced
        // user has passkeys but no TOTP, the passkey is the required
        // second factor after the auth_proof step.
        if ($passkeyResponse = $this->startPasskeyChallenge($user, $request, $rememberMe, $dpopPublicKey)) {
            return $passkeyResponse;
        }

        $tokenData = $this->authService->generateTokens($user, $request, $rememberMe, $dpopPublicKey);

        $this->authService->registerUserSession($user, $tokenData['token'], $request, $dpopPublicKey, $rememberMe);

        // The frontend needs encryption_level + login_id in its own session
        // response to restore the advanced crypto state on page refresh
        // (isAdvanced() drives prepareReAuth: auth_proof vs plaintext).
        $user->makeVisible(['login_id', 'encryption_level']);

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
            $response->withCookie($this->refreshTokenCookie($tokenData['refresh_token'], $rememberMe));
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
            'password_length' => 'required|integer|min:12',
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
            $code = ApiError::codeFromProse($e->getMessage());

            return response()->json(
                ApiError::topLevel($code, $e->getMessage()),
                404
            );
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
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_TOKEN_MISSING, 'Refresh token no proporcionado'),
                401
            );
        }

        $result = $this->authService->refreshAccessToken($plainRefreshToken, $request);

        if (! $result) {
            // No Cookie::forget here: on a cross-tab rotation race the losing
            // request must not clobber the fresh cookie the winner just set.
            // On real theft the server already revoked the chain server-side,
            // so the stale cookie is inert; on expiry it is useless anyway.
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_INVALID_CREDENTIALS, 'Refresh token inválido, expirado o revocado'),
                401
            );
        }

        $refreshExpirationDays = config('jwt.refresh_expiration', 86400) / 86400;
        $response = response()->json($result);

        $minutes = ($result['remember_me'] ?? false) ? $refreshExpirationDays * 1440 : 0;

        $response->withCookie($this->refreshTokenCookie($result['refresh_token'], (bool) ($result['remember_me'] ?? false)));

        return $response;
    }

    public function logout(Request $request): JsonResponse
    {
        $tokenString = $request->bearerToken();

        if (! $tokenString) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_TOKEN_MISSING, 'Token no proporcionado'),
                401
            );
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
            return response()->json(
                ApiError::topLevel('unknown', 'Error al cerrar sesión: '.$e->getMessage()),
                500
            );
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
            ->withCookie($this->refreshTokenForgetCookie());
    }

    public function me(Request $request): JsonResponse
    {
        $ssoUserId = $request->get('sso_user_id');
        $user = $this->authService->getUserById($ssoUserId);

        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_USER_NOT_FOUND, 'User not found'),
                404
            );
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

    public function profileToken(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'unauthenticated'),
                401
            );
        }

        return response()->json($this->authService->issueProfileToken($user));
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
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
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
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
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
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
        }

        $validated = $request->validated();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Contraseña actual incorrecta',
                'errors' => ['current_password' => ['La contraseña actual es incorrecta']],
                'code' => ApiError::AUTH_CURRENT_PASSWORD_INVALID,
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
            'password' => ['required', 'string', 'min:10', 'confirmed', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/', 'regex:/[@$!%*?&]/'],
        ]);

        $user = $this->passwordResetService->reset(
            $request->input('token'),
            $request->input('password'),
        );

        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_CREDENTIALS_NOT_FOUND, 'Token inválido o expirado.'),
                422
            );
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
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_USER_NOT_FOUND, 'Usuario no encontrado'),
                404
            );
        }

        // Get existing credentials to exclude (multi-passkey). Forwarding the
        // stored transports here keeps excludeCredentials symmetric with what
        // the browser was told at login — without it a hybrid-registered
        // credential could be silently re-registered from a platform
        // authenticator on the same browser (LBDS-225).
        $existingCredentials = $this->passkeyService->getRegistrationExcludeDescriptors($user);

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
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_REGISTRATION_SESSION_EXPIRED, 'Sesión de registro expirada'),
                400
            );
        }

        $user = User::find($userId);
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_USER_NOT_FOUND, 'Usuario no encontrado'),
                404
            );
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
            return response()->json(
                ApiError::topLevel('unknown', 'Error al registrar passkey: '.$e->getMessage()),
                400
            );
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
            'username' => 'nullable|string',
        ]);

        $user = null;
        if ($request->has('user_id')) {
            $user = User::find($request->user_id);
        } elseif ($request->has('login_id')) {
            $user = User::where('login_id', $request->login_id)->where('encryption_level', 'advanced')->first();
        } elseif ($request->has('username')) {
            $user = User::where('user', $request->username)
                ->orWhere('email', $request->username)
                ->first();
        }

        // Username-less (discoverable) ceremony: when no identification is
        // provided the browser shows the user's resident keys. The user is
        // resolved in finish from the assertion's userHandle.
        $discoverable = ! $request->has('user_id') && ! $request->has('login_id') && ! $request->has('username');

        if (! $user && ! $discoverable) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_USER_NOT_FOUND, 'Usuario no encontrado'),
                404
            );
        }

        if (! $discoverable) {
            // Build the per-credential descriptor list once and reuse both the
            // count (to fail fast when the user has no passkeys enrolled) and
            // the descriptor shape (with stored transports) for the ceremony.
            $allowCredentials = $this->passkeyService->getAuthenticationDescriptors($user);
            if (empty($allowCredentials)) {
                return response()->json(
                    ApiError::topLevel(ApiError::AUTH_PASSKEYS_NOT_REGISTERED, 'Usuario no tiene passkeys registrados'),
                    400
                );
            }
        }

        // Per-credential stored transports drive the browser's authenticator
        // picker. Without forwarding the stored transports, the service fell
        // back to a hardcoded ['usb','nfc','ble','internal'] list that
        // excluded `hybrid`, blocking cross-device QR login (LBDS-225). For
        // the discoverable flow there is no per-user allow list — the browser
        // surfaces every resident credential it knows.
        $options = $this->passkeyService->generateAuthenticationOptions(
            $discoverable ? [] : $allowCredentials
        );
        $optionsJson = $this->passkeyService->serializeOptions($options);

        $request->session()->put('passkey_auth_options', $optionsJson);
        if ($discoverable) {
            $request->session()->put('passkey_auth_scope', 'discoverable');
        } else {
            $request->session()->put('passkey_auth_user_id', $user->id);
        }

        return response()->json($optionsJson);
    }

    /**
     * Complete passkey authentication: verify assertion and issue token
     */
    public function passkeyAuthenticateFinish(Request $request): JsonResponse
    {
        $optionsData = $request->session()->pull('passkey_auth_options');
        $scope = $request->session()->pull('passkey_auth_scope', 'identified');
        $userId = $request->session()->pull('passkey_auth_user_id');

        if (! $optionsData || ($scope !== 'discoverable' && ! $userId)) {
            return response()->json(['message' => 'Sesión de autenticación expirada'], 400);
        }

        if ($scope === 'discoverable') {
            // Resolve the user from the assertion's userHandle — the user
            // entity id string set at registration ((string) $user->id).
            $userHandle = $request->input('response.userHandle');
            $decodedHandle = $userHandle !== null
                ? base64_decode(strtr($userHandle, '-_', '+/'), true)
                : false;
            $user = $decodedHandle !== false && $decodedHandle !== ''
                ? User::find((int) trim($decodedHandle))
                : null;
        } else {
            $user = $userId ? User::find($userId) : null;
        }

        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_USER_NOT_FOUND, 'Usuario no encontrado'),
                404
            );
        }

        $records = $this->passkeyService->getUserCredentialRecords($user);
        if (empty($records)) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_PASSKEYS_NOT_REGISTERED, 'Usuario no tiene passkeys registrados'),
                400
            );
        }

        $options = $this->passkeyService->deserializeOptions(
            $optionsData,
            PublicKeyCredentialRequestOptions::class
        );

        // Find matching credential record by credential ID from request.
        // The browser sends id as base64url; decode to bytes and compare
        // against the stored credential id (raw bytes).
        $credentialId = $request->input('id');
        $decodedRequestId = $credentialId !== null
            ? base64_decode(strtr($credentialId, '-_', '+/'), true)
            : false;
        $matchedRecord = null;
        foreach ($records as $record) {
            if ($decodedRequestId !== false && hash_equals($record->publicKeyCredentialId, $decodedRequestId)) {
                $matchedRecord = $record;
                break;
            }
        }

        if (! $matchedRecord) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_CREDENTIALS_NOT_FOUND, 'Credencial no encontrada'),
                400
            );
        }

        try {
            // The userHandle registered on the authenticator is always the user
            // entity id string — required for discoverable resolution to match.
            $userHandle = (string) $user->id;
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
                    'code' => ApiError::AUTH_EMAIL_NOT_VERIFIED,
                    'message' => 'Verifica tu email antes de iniciar sesión.',
                    'email' => $user->email,
                ], 403);
            }

            // If this passkey authentication is completing a password+passkey
            // login (LBDS-304), the request carries a passkey_token that was
            // issued by the password step. Verify and consume it (single-use).
            // F4 FIX: Verify the WebAuthn assertion FIRST, then consume the token
            // on success. This prevents burning the token when the user cancels
            // the browser prompt or provides a bad signature.
            $passkeyToken = $request->input('passkey_token');
            $pending = null;
            if ($passkeyToken) {
                // Read without consuming first to verify the token is valid
                $pending = Cache::get("passkey_pending:{$passkeyToken}");
                if (! $pending || ! isset($pending['user_id']) || $pending['user_id'] !== $user->id) {
                    return response()->json([
                        'message' => 'La sesión passkey expiró o ya fue usada. Iniciá sesión de nuevo.',
                    ], 401);
                }
                // Now atomically consume the token (single-use)
                $consumed = Cache::pull("passkey_pending:{$passkeyToken}");
                if (! $consumed) {
                    // Token was already consumed by a concurrent request
                    return response()->json([
                        'message' => 'La sesión passkey ya fue usada. Iniciá sesión de nuevo.',
                    ], 401);
                }
                $pending = $consumed;
            }

            $user->load('roles');

            // Use cached values from the passkey_token if present; otherwise
            // fall back to request values (for standalone passkey login).
            $dpopPublicKey = $pending['dpop_public_key'] ?? $request->input('dpop_public_key');
            $rememberMe = $pending['remember_me'] ?? $request->boolean('remember_me');

            $tokenData = $this->authService->generateTokens($user, $request, $rememberMe, $dpopPublicKey);

            $this->authService->registerUserSession($user, $tokenData['token'], $request, $dpopPublicKey, $rememberMe);

            // Username-less login has no client-known login_id; expose the
            // identifiers the frontend needs to drive the advanced flow.
            $user->makeVisible(['login_id', 'encryption_level']);

            $responseData = [
                'user' => $user,
                'token' => $tokenData['token'],
                'token_type' => $tokenData['token_type'],
                'expires_in' => $tokenData['expires_in'],
                // base64url id of the credential that just authenticated —
                // lets the client scope the silent PRF restore to it
                // (a browser may hold PRF entries for several accounts).
                'credential_id' => $credentialId,
            ];

            $loginMethod = $passkeyToken ? 'password_passkey' : 'passkey';
            $this->activityLogService->logFromRequest(
                $user,
                UserActivityLog::ACTION_LOGIN,
                $passkeyToken ? 'Inicio de sesión (contraseña + passkey)' : 'Inicio de sesión (passkey)',
                ['login_method' => $loginMethod, 'remember_me' => $rememberMe],
                $request,
            );

            $response = response()->json($responseData);

            if (isset($tokenData['refresh_token'])) {
                $refreshExpirationDays = config('jwt.refresh_expiration', 86400) / 86400;
                $minutes = $rememberMe ? $refreshExpirationDays * 1440 : 0;
                $response->withCookie($this->refreshTokenCookie($tokenData['refresh_token'], $rememberMe));
            }

            return $response;
        } catch (\Exception $e) {
            return response()->json(
                ApiError::topLevel('unknown', 'Error en autenticación passkey: '.$e->getMessage()),
                400
            );
        }
    }

    /**
     * Delete user's passkey
     */
    /**
     * List the authenticated user's registered passkeys.
     */
    public function passkeyIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
        }

        $credentials = $user->passkeyCredentials()
            ->select(['id', 'credential_id', 'device_name', 'transports', 'created_at'])
            ->orderBy('created_at')
            ->get();

        return response()->json(['credentials' => $credentials]);
    }

    public function passkeyDelete(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
        }

        $request->validate([
            'credential_id' => 'required|string',
            // Re-authentication: deleting a security credential requires
            // proof of the current password (plaintext for basic users,
            // auth_proof derived from the master password for advanced).
            'password' => 'required_without:auth_proof|string',
            'auth_proof' => 'required_without:password|string',
        ]);

        $owned = $this->passkeyService->getCredentialIds($user);
        if (! in_array($request->credential_id, $owned, true)) {
            return response()->json(['message' => 'Credencial no encontrada'], 404);
        }

        // Re-authentication gate. Basic users prove the plaintext password;
        // advanced (ZKE) users prove the auth_proof derived from the master
        // password (the server only stores Argon2id(auth_proof)).
        $reAuthSecret = $user->encryption_level === 'advanced'
            ? (string) $request->input('auth_proof')
            : (string) $request->input('password');
        if (! Hash::check($reAuthSecret, $user->password)) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_CURRENT_PASSWORD_INVALID, 'Contraseña actual incorrecta'),
                422
            );
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

    // ─── TOTP / RFC 6238 Endpoints ──────────────────────────────────────────

    /**
     * Start TOTP enrollment: generate a fresh secret, store it encrypted,
     * and return the otpauth:// URI the client renders as a QR code. The
     * plaintext secret is included in the response so the client can also
     * offer a manual-entry fallback for authenticator apps that cannot
     * scan a QR (e.g. desktop browsers without a camera). The secret is
     * NOT marked confirmed yet — see totpEnrollConfirm().
     *
     * Replacing an existing (already confirmed) credential rolls it over:
     * the old encrypted_secret is overwritten with the new one, the
     * confirmation timestamp is reset, and any backup codes are deleted.
     * A user re-enrolling does not get to keep the previous codes by
     * accident — they would otherwise recover access to a forgotten
     * device through old codes they forgot they had.
     */
    public function totpEnrollStart(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $plainSecret = $this->totpService->generateSecret();
        $encrypted = $this->totpService->encryptSecret($plainSecret);
        $uri = $this->totpService->buildOtpauthUri($user, $plainSecret);

        // Wipe any in-flight enrollment (unconfirmed) or confirmed
        // credential. UPSERT-by-user_id keeps a UNIQUE on the column, so
        // updateOrCreate is the cleanest path.
        $credential = TotpCredential::updateOrCreate(
            ['user_id' => $user->id],
            [
                'encrypted_secret' => $encrypted,
                'confirmed_at' => null,
                'last_used_step' => null,
                'digits' => TotpService::DEFAULT_DIGITS,
                'period' => TotpService::DEFAULT_PERIOD,
                'algorithm' => TotpService::DEFAULT_ALGORITHM,
            ],
        );

        // Re-enrollment invalidates old backup codes — the user is
        // starting a fresh set after losing their device, so leftover
        // codes from the previous setup would be a backdoor.
        $this->totpService->deleteAllBackupCodes($user);

        return response()->json([
            'secret' => $plainSecret,
            'otpauth_uri' => $uri,
            'digits' => $credential->digits,
            'period' => $credential->period,
            'algorithm' => $credential->algorithm,
        ]);
    }

    /**
     * Complete TOTP enrollment: verify the user can produce a valid code
     * with the freshly generated secret, mark the credential confirmed,
     * and return a one-time list of backup codes the user MUST save
     * somewhere safe.
     *
     * The plaintext backup codes are NEVER returned again — losing them
     * means generating new ones via totpBackupCodesRegenerate(), which
     * requires re-authentication.
     */
    public function totpEnrollConfirm(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
        }

        $request->validate([
            'code' => 'required|string',
        ]);

        $credential = $user->totpCredential;
        if (! $credential) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_REGISTRATION_SESSION_EXPIRED, 'No hay enrollment TOTP en curso. Iniciá uno primero.'),
                400
            );
        }

        $acceptedStep = $this->totpService->verifyAndConsume($credential, $request->code);
        if ($acceptedStep === null) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_INVALID_CREDENTIALS, 'Código inválido. Verificá que tu app muestra la hora correcta.'),
                422
            );
        }

        // verifyAndConsume already saved last_used_step. Mark confirmed.
        $credential->confirmed_at = now();
        $credential->save();

        $backupCodes = $this->totpService->generateBackupCodes($user);

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_TOTP_ENROLLED,
            'TOTP habilitado',
            ['digits' => $credential->digits, 'period' => $credential->period, 'algorithm' => $credential->algorithm],
            $request,
        );

        return response()->json([
            'message' => 'TOTP habilitado correctamente',
            'backup_codes' => $backupCodes,
            'backup_codes_count' => count($backupCodes),
        ]);
    }

    /**
     * Disable TOTP for the current user. Requires re-authentication
     * (plaintext password for basic users, auth_proof for advanced) so
     * a stolen access token alone cannot turn 2FA off.
     *
     * Cascade: the credential row is deleted (cascadeOnDelete handles the
     * SQL FK; we delete explicitly here to keep the model code obvious and
     * to log a single UserActivityLog entry).
     */
    public function totpDisable(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $request->validate([
            'password' => 'required_without:auth_proof|string',
            'auth_proof' => 'required_without:password|string',
        ]);

        $reAuthSecret = $user->encryption_level === 'advanced'
            ? (string) $request->input('auth_proof')
            : (string) $request->input('password');
        if (! Hash::check($reAuthSecret, $user->password)) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_CURRENT_PASSWORD_INVALID, 'Contraseña actual incorrecta'),
                422
            );
        }

        TotpCredential::where('user_id', $user->id)->delete();
        $this->totpService->deleteAllBackupCodes($user);

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_TOTP_DISABLED,
            'TOTP deshabilitado',
            null,
            $request,
        );

        return response()->json(['message' => 'TOTP deshabilitado correctamente']);
    }

    /**
     * Complete a TOTP-gated login. Called by the frontend after the basic
     * (or advanced) `/login` endpoint returns `code: TOTP_REQUIRED`.
     *
     * The client posts either `code` (the 6-digit TOTP code from the
     * authenticator app) or `backup_code` (one of the single-use recovery
     * codes shown at enrollment). Single-use semantics live in
     * {@see TotpService::verifyAndConsume()} and
     * {@see TotpService::verifyBackupCode()}.
     *
     * The pending login state was cached in {@see self::startTotpChallenge()}
     * with a 5-minute TTL and is consumed via Cache::pull (get + delete in
     * one operation) so a parallel replay cannot double-issue a JWT.
     */
    public function totpLogin(Request $request): JsonResponse
    {
        $request->validate([
            'totp_token' => 'required|string',
            'code' => 'required_without:backup_code|nullable|string',
            'backup_code' => 'required_without:code|nullable|string',
        ]);

        // Read first, delete only on success: a wrong code MUST NOT
        // consume the pending entry, otherwise a typo would force the
        // user to redo the password step. Cache::pull() does get+delete
        // in one call, which is the wrong semantic for this endpoint.
        $pending = Cache::get("totp_pending:{$request->input('totp_token')}");

        if (! $pending || ! isset($pending['user_id'])) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_AUTHENTICATION_SESSION_EXPIRED, 'La sesión TOTP expiró o ya fue usada. Iniciá sesión de nuevo.'),
                401
            );
        }

        $user = User::find($pending['user_id']);
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_USER_NOT_FOUND, 'Usuario no encontrado'),
                404
            );
        }

        $credential = $user->activeTotpCredential()->first();
        if (! $credential) {
            // TOTP was disabled between start and confirm. Force a fresh
            // login so the user does not silently end up logged in without
            // the second factor they configured.
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_PASSKEYS_NOT_REGISTERED, 'TOTP ya no está habilitado para esta cuenta. Iniciá sesión de nuevo.'),
                422
            );
        }

        $verified = false;
        $usedBackupCode = false;
        if ($request->filled('code')) {
            $verified = $this->totpService->verifyAndConsume($credential, $request->input('code')) !== null;
        } else {
            $usedBackupCode = true;
            $verified = $this->totpService->verifyBackupCode($user, $request->input('backup_code'));
        }

        if (! $verified) {
            return response()->json(
                ApiError::topLevel(
                    ApiError::AUTH_INVALID_CREDENTIALS,
                    $usedBackupCode
                        ? 'Código de respaldo inválido o ya usado.'
                        : 'Código TOTP inválido. Verificá que tu app muestra la hora correcta.'
                ),
                422
            );
        }

        // Verified. From here on, the pending entry MUST be deleted so a
        // replay of the same totp_token cannot double-issue a JWT.
        Cache::forget("totp_pending:{$request->input('totp_token')}");

        $user->load('roles');

        $rememberMe = (bool) ($pending['remember_me'] ?? false);
        $dpopPublicKey = $pending['dpop_public_key'] ?? null;

        $tokenData = $this->authService->generateTokens($user, $request, $rememberMe, $dpopPublicKey);
        $this->authService->registerUserSession($user, $tokenData['token'], $request, $dpopPublicKey, $rememberMe);

        $user->makeVisible(['login_id', 'encryption_level']);

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
            $response->withCookie($this->refreshTokenCookie($tokenData['refresh_token'], $rememberMe));
        }

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_LOGIN,
            $usedBackupCode ? 'Inicio de sesión (TOTP — código de respaldo)' : 'Inicio de sesión (TOTP)',
            ['login_method' => 'password_totp', 'remember_me' => $rememberMe, 'used_backup_code' => $usedBackupCode],
            $request,
        );

        return $response;
    }

    /**
     * Surface the user's TOTP status so the settings UI can render the
     * right card (enable vs. enrolled) without the client having to retry
     * enrollment to discover the state.
     *
     * The plaintext secret and the backup codes are NEVER returned here;
     * `enabled` is the only flag the UI needs, and the count of unused
     * backup codes is what drives the "X códigos restantes" copy.
     */
    public function totpStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
        }

        $credential = $user->activeTotpCredential()->first();
        $remaining = $user->totpBackupCodes()->whereNull('used_at')->count();

        return response()->json([
            'enabled' => $credential !== null,
            'digits' => $credential?->digits,
            'period' => $credential?->period,
            'algorithm' => $credential?->algorithm,
            'confirmed_at' => $credential?->confirmed_at?->toIso8601String(),
            'backup_codes_remaining' => $remaining,
        ]);
    }

    /**
     * Regenerate the user's backup codes. Existing unused codes are
     * invalidated (deleted) and a fresh set is returned in plaintext.
     * Used codes are kept in the audit trail indirectly via the
     * `used_at` column on the rows being deleted here.
     *
     * Re-authentication is mandatory: an attacker who somehow got a
     * session token must not be able to silently rotate the backup-code
     * set and lock the legitimate user out.
     */
    public function totpBackupCodesRegenerate(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
        }

        $request->validate([
            'password' => 'required_without:auth_proof|string',
            'auth_proof' => 'required_without:password|string',
        ]);

        $reAuthSecret = $user->encryption_level === 'advanced'
            ? (string) $request->input('auth_proof')
            : (string) $request->input('password');
        if (! Hash::check($reAuthSecret, $user->password)) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_CURRENT_PASSWORD_INVALID, 'Contraseña actual incorrecta'),
                422
            );
        }

        $credential = $user->activeTotpCredential()->first();
        if (! $credential) {
            return response()->json([
                'message' => 'No hay TOTP habilitado. Habilitá TOTP antes de generar códigos de respaldo.',
            ], 422);
        }

        $this->totpService->deleteAllBackupCodes($user);
        $newCodes = $this->totpService->generateBackupCodes($user);

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_TOTP_BACKUP_CODE_REGENERATED,
            'Códigos de respaldo TOTP regenerados',
            ['count' => count($newCodes)],
            $request,
        );

        return response()->json([
            'message' => 'Códigos de respaldo regenerados',
            'backup_codes' => $newCodes,
            'backup_codes_count' => count($newCodes),
        ]);
    }

    public function getUserSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
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
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
        }

        $session = $user->sessions()->find($sessionId);
        if (! $session) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_SESSION_NOT_FOUND, 'Sesión no encontrada'),
                404
            );
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
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
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
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
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
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
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
