<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
            ->where('token', $tokenString)
            ->update(['token' => $newTokenData['token'], 'last_used_at' => now()]);

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

    public function getUserById(int $id): ?User
    {
        return User::with('roles')->find($id);
    }

    public function getJwks(): array
    {
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
