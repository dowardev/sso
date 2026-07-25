<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Services;

use App\Models\User;
use App\Models\UserRefreshToken;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    private JwtService $jwtService;
    private SessionService $sessionService;

    public function __construct(JwtService $jwtService, SessionService $sessionService)
    {
        $this->jwtService = $jwtService;
        $this->sessionService = $sessionService;
    }

    public function register(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'user' => $data['user'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        return ['user' => $user];
    }

    public function login(string $loginField, string $password): ?User
    {
        $user = User::where('email', $loginField)
            ->orWhere('user', $loginField)
            ->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }

    public function generateAuthToken(User $user, ?string $dpopPublicKey = null, bool $rememberMe = false): array
    {
        return $this->jwtService->generateToken($user, $dpopPublicKey, $rememberMe);
    }

    public function registerUserSession(User $user, string $token, Request $request, ?string $dpopPublicKey = null, bool $rememberMe = false): void
    {
        $this->sessionService->registerSession($user, $token, $request, $dpopPublicKey, $rememberMe);
    }

    public function refreshToken(string $tokenString, ?string $clientId = null): ?array
    {
        $token = $this->jwtService->parseAndValidateToken($tokenString);

        if (!$token) {
            return null;
        }

        $userId = $token->claims()->get('sub');
        $user = User::with('roles')->find($userId);

        if (!$user) {
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
                'roles' => $user->roles->map(fn($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'description' => $role->description,
                    'is_protected' => $role->is_protected,
                ]),
            ],
        ];
    }

    public function generateTokens(User $user, Request $request, bool $rememberMe = false, ?string $dpopPublicKey = null): array
    {
        $accessTokenData = $this->jwtService->generateToken($user, $dpopPublicKey, false);

        $result = [
            'token' => $accessTokenData['token'],
            'token_type' => $accessTokenData['token_type'],
            'expires_in' => $accessTokenData['expires_in'],
        ];

        $refreshToken = $this->generateRefreshToken($user, $request, $rememberMe);
        $result['refresh_token'] = $refreshToken['plain'];

        return $result;
    }

    public function generateRefreshToken(User $user, Request $request, bool $rememberMe = false): array
    {
        $plain = Str::random(64);
        $hash = hash('sha256', $plain);
        $expiresAt = now()->addSeconds((int) config('jwt.refresh_expiration', 86400));

        $model = UserRefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => $hash,
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'expires_at' => $expiresAt,
            'remember_me' => $rememberMe,
        ]);

        return ['plain' => $plain, 'model' => $model];
    }

    public function refreshAccessToken(string $plainRefreshToken, Request $request): ?array
    {
        $hash = hash('sha256', $plainRefreshToken);
        $existing = UserRefreshToken::where('token_hash', $hash)->first();

        if (!$existing || !$existing->isValid()) {
            return null;
        }

        // Check inactivity timeout
        $inactivitySeconds = (int) config('jwt.refresh_inactivity', 604800);
        if ($existing->last_used_at !== null && $existing->last_used_at->addSeconds($inactivitySeconds)->isPast()) {
            $existing->revoke();
            return null;
        }

        // Detect reuse: if this token was already rotated (it has rotatedFrom or was already used)
        if ($existing->rotated_from_id !== null || $existing->revoked_at !== null) {
            // Token theft — revoke entire chain
            UserRefreshToken::where('user_id', $existing->user_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            return null;
        }

        $user = User::with('roles')->find($existing->user_id);
        if (!$user) {
            return null;
        }

        // Revoke old token
        $existing->revoke();

        // Generate new access + refresh pair
        $dpopPublicKey = null;
        $currentTokenStr = $request->bearerToken();
        if ($currentTokenStr) {
            $parsed = $this->jwtService->parseAndValidateToken($currentTokenStr);
            if ($parsed && $parsed->claims()->get('cnf')) {
                $dpopPublicKey = $existing->user_agent; // placeholder — real DPoP logic
            }
        }

        $accessTokenData = $this->jwtService->generateToken($user, $dpopPublicKey, false);

        // Create new refresh token (rotated)
        $newPlain = Str::random(64);
        $newHash = hash('sha256', $newPlain);
        $newExpiresAt = now()->addSeconds((int) config('jwt.refresh_expiration', 86400));

        UserRefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => $newHash,
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'expires_at' => $newExpiresAt,
            'rotated_from_id' => $existing->id,
            'remember_me' => $existing->remember_me,
        ]);

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
                'roles' => $user->roles->map(fn($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'description' => $role->description,
                    'is_protected' => $role->is_protected,
                ]),
            ],
        ];
    }

    public function revokeRefreshToken(string $plainRefreshToken): void
    {
        $hash = hash('sha256', $plainRefreshToken);
        UserRefreshToken::where('token_hash', $hash)->each(fn($t) => $t->revoke());
    }

    public function revokeAllUserRefreshTokens(User $user): void
    {
        UserRefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function getUserById(int $id): ?User
    {
        return User::with('roles')->find($id);
    }

    public function getUserFromToken(string $tokenString): ?User
    {
        $token = $this->jwtService->parseAndValidateToken($tokenString);

        if (!$token) {
            return null;
        }

        $userId = $token->claims()->get('sub');
        return User::find($userId);
    }

    public function getJwks(): array
    {
        return Cache::remember('jwks', 86400, function () {
            $privateKeyContent = file_get_contents(config('jwt.private_key_path'));
            $keyDetails = openssl_pkey_get_details(openssl_pkey_get_private($privateKeyContent));

            if (!$keyDetails) {
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
                    ]
                ]
            ];
        });
    }

    public function checkSessionFromRequest(Request $request): array
    {
        $ssoUserId = $request->get('sso_user_id');

        if (!$ssoUserId) {
            $tokenString = $request->bearerToken();

            if (!$tokenString) {
                $tokenString = $request->cookie('sso_token');
            }

            if (!$tokenString) {
                return ['authenticated' => false];
            }

            $token = $this->jwtService->parseAndValidateToken($tokenString);
            if (!$token) {
                return ['authenticated' => false];
            }

            $ssoUserId = $token->claims()->get('sub');
        }

        $user = User::with('roles')->find($ssoUserId);

        if (!$user) {
            return ['authenticated' => false];
        }

        return [
            'authenticated' => true,
            'user' => [
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
            ],
        ];
    }
}
