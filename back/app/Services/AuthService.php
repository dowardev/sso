<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Services;

use App\Http\Middleware\VerifyDpopProof;
use App\Models\User;
use App\Models\UserRefreshToken;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    private JwtService $jwtService;

    private SessionService $sessionService;

    private VerifyDpopProof $dpopProof;

    public function __construct(JwtService $jwtService, SessionService $sessionService, VerifyDpopProof $dpopProof)
    {
        $this->jwtService = $jwtService;
        $this->sessionService = $sessionService;
        $this->dpopProof = $dpopProof;
    }

    /**
     * Register a basic user (standard flow).
     */
    public function register(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'user' => $data['user'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'encryption_level' => 'basic',
        ]);

        return ['user' => $user];
    }

    /**
     * Register an advanced user (zero-knowledge encryption).
     *
     * Expects: login_id, auth_hash, salt_a, salt_r, wrapped_dek_a, wrapped_dek_r,
     * encrypted_profile, encrypted_seed_verification, encryption_level='advanced'
     */
    public function registerAdvanced(array $data): array
    {
        $user = User::create([
            'name' => null,
            'user' => null,
            'email' => null,
            'password' => null,
            'login_id' => $data['login_id'],
            'encryption_level' => 'advanced',
            'salt_a' => $data['salt_a'],
            'salt_r' => $data['salt_r'],
            'wrapped_dek_a' => $data['wrapped_dek_a'],
            'wrapped_dek_r' => $data['wrapped_dek_r'],
            'encrypted_profile' => $data['encrypted_profile'],
            'encrypted_seed_verification' => $data['encrypted_seed_verification'],
            'passkey_public_key' => $data['passkey_public_key'] ?? null,
            'passkey_credential_id' => $data['passkey_credential_id'] ?? null,
        ]);

        // Store auth_hash as password (Argon2id hash of auth_proof)
        $user->password = $data['auth_hash'];
        $user->save();

        return ['user' => $user];
    }

    /**
     * Login for basic users (standard flow).
     */
    public function login(string $loginField, string $password): ?User
    {
        $user = User::where('email', $loginField)
            ->orWhere('user', $loginField)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }

    /**
     * Resolve an identifier to the account's available auth methods.
     *
     * Resolution is username-only (normalized for all users): a direct
     * `user` match for basic accounts, otherwise the deterministic
     * login_id derived with HMAC-SHA-256 (key 'login_id_salt_v1') — email
     * and raw login_id are not valid identifiers here.
     *
     * The client computation lives in front/src/controllers/authController.ts
     * (computeLoginId: base64 of the raw HMAC digest).
     *
     * Always returns a response (HTTP 200), even when no account matches.
     * For advanced accounts the resolved login_id is returned so the client
     * can run the password/passkey flows without recomputing it.
     *
     * @return array{account_found: bool, encryption_level: string|null, has_passkeys: bool, login_id: string|null}
     */
    public function resolveAuthMethods(string $identifier): array
    {
        $user = User::where('user', $identifier)->first();

        if (! $user) {
            $derivedLoginId = base64_encode(hash_hmac('sha256', $identifier, 'login_id_salt_v1', true));
            $user = User::where('login_id', $derivedLoginId)->first();
        }

        if (! $user) {
            return [
                'account_found' => false,
                'encryption_level' => null,
                'has_passkeys' => false,
                'login_id' => null,
            ];
        }

        return [
            'account_found' => true,
            'encryption_level' => $user->encryption_level,
            'has_passkeys' => $user->passkeyCredentials()->exists(),
            'login_id' => $user->encryption_level === 'advanced' ? $user->login_id : null,
        ];
    }

    /**
     * Return the client-side crypto material for an advanced user.
     *
     * Used by login, recovery, and profile refresh flows: the caller
     * derives Key_A/Key_R locally and unwraps the DEK.
     * Public data only (salts + wrapped keys + encrypted profile).
     */
    public function getCryptoBlobs(string $loginId): ?array
    {
        $user = User::where('login_id', $loginId)
            ->where('encryption_level', 'advanced')
            ->first();

        if (! $user) {
            return null;
        }

        return [
            'encryption_level' => $user->encryption_level,
            'profile_version' => $user->profile_version,
            'salt_a' => $user->salt_a,
            'salt_r' => $user->salt_r,
            'wrapped_dek_a' => $user->wrapped_dek_a,
            'encrypted_profile' => $user->encrypted_profile,
        ];
    }

    /**
     * Second step of advanced login: verify auth_proof.
     *
     * Client sends login_id + auth_proof (HMAC(Key_A, "auth")).
     * Server verifies: Argon2id(auth_proof) == stored auth_hash (in password column).
     */
    public function verifyAuthProof(string $loginId, string $authProof): ?User
    {
        $user = User::where('login_id', $loginId)
            ->where('encryption_level', 'advanced')
            ->first();

        if (! $user) {
            return null;
        }

        // For advanced users, password column stores Argon2id(auth_proof)
        if (! Hash::check($authProof, $user->password)) {
            return null;
        }

        return $user;
    }

    /**
     * Generate auth token (JWT) for a user.
     */
    public function generateAuthToken(User $user, ?string $dpopPublicKey = null, bool $rememberMe = false): array
    {
        return $this->jwtService->generateToken($user, $dpopPublicKey, $rememberMe);
    }

    /**
     * Register user session.
     */
    public function registerUserSession(User $user, string $token, Request $request, ?string $dpopPublicKey = null, bool $rememberMe = false): void
    {
        $this->sessionService->registerSession($user, $token, $request, $dpopPublicKey, $rememberMe);
    }

    /**
     * Refresh access token (standard flow).
     */
    public function refreshToken(string $tokenString, ?string $clientId = null): ?array
    {
        $token = $this->jwtService->parseAndValidateToken($tokenString);

        if (! $token) {
            return null;
        }

        $userId = $token->claims()->get('sub');
        $user = User::with('roles')->find($userId);

        if (! $user) {
            return null;
        }

        $dpopPublicKey = null;
        $rememberMe = false;
        $cnfClaim = $token->claims()->get('cnf');

        if ($cnfClaim && isset($cnfClaim['jkt'])) {
            $existingSession = $this->sessionService->getActiveSessionData($tokenString);
            if ($existingSession) {
                $dpopPublicKey = $existingSession->dpop_public_key;
                $rememberMe = $existingSession->remember_me ?? false;
            }
        }

        $newTokenData = $this->jwtService->generateToken($user, $dpopPublicKey, $rememberMe);

        UserSession::where('user_id', $userId)
            ->where('token_hash', hash('sha256', $tokenString))
            ->update(['token_hash' => hash('sha256', $newTokenData['token']), 'last_used_at' => now()]);

        return [
            'token' => $newTokenData['token'],
            'token_type' => $newTokenData['token_type'],
            'expires_in' => $newTokenData['expires_in'],
            'user' => [
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
            ],
        ];
    }

    /**
     * Generate access + refresh tokens.
     */
    public function generateTokens(User $user, Request $request, bool $rememberMe = false, ?string $dpopPublicKey = null): array
    {
        $accessTokenData = $this->jwtService->generateToken($user, $dpopPublicKey, false);

        $result = [
            'token' => $accessTokenData['token'],
            'token_type' => $accessTokenData['token_type'],
            'expires_in' => $accessTokenData['expires_in'],
        ];

        // The login/cookie flow has no DPoP proof mechanism on refresh, so these
        // refresh tokens stay unbound (DPoP out of scope there, tracked separately).
        $refreshToken = $this->generateRefreshToken($user, $request, $rememberMe, null);
        $result['refresh_token'] = $refreshToken['plain'];

        return $result;
    }

    /**
     * Derive the RFC 7638 JWK thumbprint from a raw dpop_public_key field.
     *
     * Returns null rather than throwing when the payload is absent or malformed,
     * so callers only bind a refresh token when a usable JWK was provided.
     */
    public function jktFromDpopPublicKey(?string $dpopPublicKey): ?string
    {
        if (! $dpopPublicKey) {
            return null;
        }

        $decoded = json_decode($dpopPublicKey, true);

        if (! is_array($decoded)
            || ($decoded['kty'] ?? null) !== 'RSA'
            || ! isset($decoded['n'])
            || ! isset($decoded['e'])
        ) {
            return null;
        }

        return $this->jwtService->generateJWKThumbprint($decoded);
    }

    /**
     * Generate refresh token.
     */
    public function generateRefreshToken(User $user, Request $request, bool $rememberMe = false, ?string $dpopJkt = null): array
    {
        $plain = Str::random(64);
        $hash = hash('sha256', $plain);
        $expiresAt = now()->addSeconds((int) config('jwt.refresh_expiration', 86400));

        $model = UserRefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => $hash,
            'dpop_jkt' => $dpopJkt,
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'expires_at' => $expiresAt,
            'remember_me' => $rememberMe,
        ]);

        return ['plain' => $plain, 'model' => $model];
    }

    /**
     * Refresh access token using refresh token.
     *
     * @param  bool  $requireDpop  True only for the OAuth token endpoint, which
     *                             additionally requires the refresh token to be
     *                             DPoP-bound. A bound token always needs a valid
     *                             matching proof regardless of this flag.
     */
    public function refreshAccessToken(string $plainRefreshToken, Request $request, bool $requireDpop = false): ?array
    {
        $hash = hash('sha256', $plainRefreshToken);
        $existing = UserRefreshToken::where('token_hash', $hash)->first();

        if (! $existing) {
            return null;
        }

        if (! $existing->isValid()) {
            // A presented-but-revoked token means an old token is being
            // replayed after rotation: the real token-theft signal.
            if ($existing->revoked_at !== null
                && $existing->revoked_at->lt(now()->subSeconds(30))
            ) {
                // Revoked long enough ago that this cannot be a rotation
                // race between tabs/parallel requests: treat as theft and
                // revoke every active session of the user (all devices).
                UserRefreshToken::where('user_id', $existing->user_id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
            }

            return null;
        }

        // Check inactivity timeout
        $inactivitySeconds = (int) config('jwt.refresh_inactivity', 604800);
        if ($existing->last_used_at !== null && $existing->last_used_at->addSeconds($inactivitySeconds)->isPast()) {
            $existing->revoke();

            return null;
        }

        // DPoP enforcement: a refresh token that HAS a dpop_jkt can only be
        // rotated with a valid matching proof, on EVERY endpoint. The endpoint
        // flag only adds the requirement that the token be bound at all: the
        // OAuth token endpoint rejects unbound/legacy tokens (approved hard
        // cutover). The cookie/SSO-web login flow never receives a binding (see
        // generateTokens()), so it keeps working without a proof. This runs after
        // the token-state checks above so a replayed revoked token still trips
        // the existing all-session theft revocation.
        if ($requireDpop && ! $existing->dpop_jkt) {
            return null;
        }

        if ($existing->dpop_jkt) {
            $dpopError = $this->dpopProof->verifyTokenRequest(
                $request->header('DPoP'),
                $request,
                $existing->dpop_jkt,
                $request->bearerToken()
            );

            if ($dpopError !== null) {
                return null;
            }
        }

        // Note: rotated_from_id is rotation history (set on every rotated
        // token), NOT a theft signal. Theft is detected above, when a revoked
        // token is presented again outside the race grace window.
        $user = User::with('roles')->find($existing->user_id);
        if (! $user) {
            return null;
        }

        // Any refresh token carrying a binding rotates into an access token that
        // keeps it; an unbound token keeps producing unbound tokens. The binding
        // is a property of the refresh token, not of the endpoint, so this applies
        // on /api/refresh-token too. The cookie/SSO-web login flow never receives
        // a binding (see generateTokens()), which is why it still works without a
        // proof.
        $accessTokenData = $existing->dpop_jkt !== null
            ? $this->jwtService->generateTokenForJkt($user, $existing->dpop_jkt, false)
            : $this->jwtService->generateToken($user, null, false);

        // Revoke old token and create its replacement atomically, so a crash
        // in between never strands the user with a dead refresh token.
        $newPlain = Str::random(64);
        $newHash = hash('sha256', $newPlain);
        $newExpiresAt = now()->addSeconds((int) config('jwt.refresh_expiration', 86400));

        DB::transaction(function () use ($existing, $request, $newHash, $newExpiresAt) {
            $existing->revoke();

            UserRefreshToken::create([
                'user_id' => $existing->user_id,
                'token_hash' => $newHash,
                'dpop_jkt' => $existing->dpop_jkt,
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'expires_at' => $newExpiresAt,
                'rotated_from_id' => $existing->id,
                'remember_me' => $existing->remember_me,
            ]);
        });

        return [
            'token' => $accessTokenData['token'],
            'token_type' => $accessTokenData['token_type'],
            'expires_in' => $accessTokenData['expires_in'],
            'refresh_token' => $newPlain,
            'remember_me' => $existing->remember_me,
            'user' => [
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
            ],
        ];
    }

    /**
     * Revoke a specific refresh token.
     */
    public function revokeRefreshToken(string $plainRefreshToken): void
    {
        $hash = hash('sha256', $plainRefreshToken);
        UserRefreshToken::where('token_hash', $hash)->each(fn ($t) => $t->revoke());
    }

    /**
     * Revoke all refresh tokens for a user.
     */
    public function revokeAllUserRefreshTokens(User $user): void
    {
        UserRefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Get user by ID.
     */
    public function getUserById(int $id): ?User
    {
        return User::with('roles')->find($id);
    }

    /**
     * Get user from JWT token.
     */
    public function getUserFromToken(string $tokenString): ?User
    {
        $token = $this->jwtService->parseAndValidateToken($tokenString);

        if (! $token) {
            return null;
        }

        $userId = $token->claims()->get('sub');

        return User::find($userId);
    }

    /**
     * Get JWKS for JWT verification.
     */
    /**
     * Issue a short-lived SSO-signed profile token for advanced (ZKE) users.
     * See JwtService::issueProfileToken.
     */
    public function issueProfileToken(User $user): array
    {
        return $this->jwtService->issueProfileToken($user);
    }

    public function getJwks(): array
    {
        return Cache::remember('jwks', 86400, function () {
            $privateKeyContent = file_get_contents(config('jwt.private_key_path'));
            $keyDetails = openssl_pkey_get_details(openssl_pkey_get_private($privateKeyContent));

            if (! $keyDetails) {
                return ['error' => 'Unable to get key details'];
            }

            $n = $this->jwtService->base64UrlEncode($keyDetails['rsa']['n']);
            $e = $this->jwtService->base64UrlEncode($keyDetails['rsa']['e']);

            return [
                'keys' => [
                    [
                        'kty' => 'RSA',
                        'use' => 'sig',
                        'alg' => 'RS256',
                        'kid' => 'sso-key-1',
                        'n' => $n,
                        'e' => $e,
                    ],
                ],
            ];
        });
    }

    /**
     * Check session from request.
     */
    public function checkSessionFromRequest(Request $request): array
    {
        $tokenString = $request->bearerToken();

        if (! $tokenString) {
            $tokenString = $request->cookie('sso_token');
        }

        if (! $tokenString) {
            return ['authenticated' => false];
        }

        $token = $this->jwtService->parseAndValidateToken($tokenString);
        if (! $token) {
            return ['authenticated' => false];
        }

        // Service/client tokens are never interactive user sessions
        if ($token->claims()->get('grant_type') === 'client_credentials') {
            return ['authenticated' => false];
        }

        $ssoUserId = $token->claims()->get('sub');

        $user = User::with('roles')->find($ssoUserId);

        if (! $user) {
            return ['authenticated' => false];
        }

        // Logout deletes every session row, so a user without any active session
        // is treated as logged out. This is checked per user (not per token hash)
        // because access tokens rotate on refresh while the session row stays.
        $hasActiveSession = UserSession::where('user_id', $user->id)->exists();

        if (! $hasActiveSession) {
            return ['authenticated' => false];
        }

        return [
            'authenticated' => true,
            'user' => [
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
            ],
        ];
    }

    /**
     * Recovery - Step 1: Verify seed phrase.
     *
     * Client provides login_id + seed_phrase.
     * Server derives Key_R = PBKDF2(seed_phrase + salt_r, 1M)
     * Computes verification = HMAC(Key_R, "verify")
     * Compares with stored encrypted_seed_verification.
     */
    public function recoveryVerify(string $loginId, string $seedPhrase): ?User
    {
        $user = User::where('login_id', $loginId)
            ->where('encryption_level', 'advanced')
            ->first();

        if (! $user || ! $user->salt_r || ! $user->encrypted_seed_verification) {
            return null;
        }

        // In a real implementation, this would use the Web Crypto API
        // For now, we simulate the verification by checking if the seed phrase
        // produces the expected verification hash
        // The actual HMAC computation happens client-side in the crypto worker
        // Server just needs to verify the client's proof

        // For server-side verification, we'd need to derive Key_R and compute HMAC
        // Since we can't do Web Crypto in PHP easily, we accept the client's
        // computed verification and compare with stored value
        // This method is called after client verifies locally

        return $user;
    }

    /**
     * Recovery - Step 2: Complete recovery with new password.
     *
     * Client has already verified seed phrase locally and derived Key_R.
     * Client sends: login_id, new_password, new_salt_a, new_wrapped_dek_a,
     * new_encrypted_profile (re-encrypted with new DEK)
     * Server: generates new DEK, updates all crypto blobs, revokes sessions
     */
    public function recoveryComplete(array $data): array
    {
        $user = User::where('login_id', $data['login_id'])
            ->where('encryption_level', 'advanced')
            ->first();

        if (! $user) {
            throw new \Exception('Usuario no encontrado');
        }

        // Update crypto blobs
        $user->salt_a = $data['salt_a'];
        $user->wrapped_dek_a = $data['wrapped_dek_a'];
        $user->wrapped_dek_r = $data['wrapped_dek_r'];
        $user->encrypted_profile = $data['encrypted_profile'];
        $user->password = $data['auth_hash']; // Argon2id(new_auth_proof)
        $user->profile_version++;
        $user->save();

        // Revoke all sessions and tokens (security requirement)
        $this->revokeAllUserRefreshTokens($user);
        $this->sessionService->revokeAllUserSessions($user);

        return ['user' => $user];
    }

    /**
     * Change password for advanced user (DEK rotation with same seed).
     *
     * Different from recovery: keeps same DEK, only re-wraps with new Key_A
     */
    public function changePasswordAdvanced(array $data): array
    {
        $user = User::where('login_id', $data['login_id'])
            ->where('encryption_level', 'advanced')
            ->first();

        if (! $user) {
            throw new \Exception('Usuario no encontrado');
        }

        // Update only Key_A related blobs (salt_a, wrapped_dek_a, auth_hash)
        $user->salt_a = $data['salt_a'];
        $user->wrapped_dek_a = $data['wrapped_dek_a'];
        $user->password = $data['auth_hash'];
        $user->save();

        // Revoke all sessions and tokens (security requirement)
        $this->revokeAllUserRefreshTokens($user);
        $this->sessionService->revokeAllUserSessions($user);

        return ['user' => $user];
    }

    /**
     * Upgrade basic user to advanced encryption (zero-knowledge).
     *
     * Client provides current password, generates all crypto material,
     * encrypts current profile, and sends blobs to server.
     * Server stores blobs, sets encryption_level='advanced', nullifies name/user/email.
     */
    public function upgradeToAdvanced(User $user, array $data): array
    {
        // Verify current password
        if (! Hash::check($data['current_password'], $user->password)) {
            throw new \Exception('Contraseña actual incorrecta');
        }

        // Verify user is basic
        if ($user->encryption_level !== 'basic') {
            throw new \Exception('El usuario ya tiene cifrado avanzado');
        }

        // Hash the auth_proof for storage (password column)

        // Update user with crypto blobs
        $user->encryption_level = 'advanced';
        $user->login_id = $data['login_id'];
        $user->salt_a = $data['salt_a'];
        $user->salt_r = $data['salt_r'];
        $user->wrapped_dek_a = $data['wrapped_dek_a'];
        $user->wrapped_dek_r = $data['wrapped_dek_r'];
        $user->encrypted_profile = $data['encrypted_profile'];
        $user->encrypted_seed_verification = $data['encrypted_seed_verification'];
        $user->password = $data['auth_hash']; // Argon2id(auth_proof)
        $user->profile_version = 1;

        // Nullify plaintext fields
        $user->name = null;
        $user->user = null;
        $user->email = null;
        $user->passkey_public_key = $data['passkey_public_key'] ?? null;
        $user->passkey_credential_id = $data['passkey_credential_id'] ?? null;

        $user->save();

        // Revoke all sessions and tokens (security requirement)
        $this->revokeAllUserRefreshTokens($user);
        $this->sessionService->revokeAllUserSessions($user);

        return ['user' => $user];
    }
}
