<?php

namespace App\Http\Controllers;

use App\Models\SsoClient;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Lcobucci\JWT\Configuration;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Validation\Constraint;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\Clock\SystemClock;

class AuthController extends Controller
{
    private Configuration $jwtConfig;
    private int $tokenExpiration;
    private string $issuer;

    public function __construct()
    {
        $this->jwtConfig = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::file(config('jwt.private_key_path')),
            InMemory::file(config('jwt.public_key_path'))
        );

        $this->tokenExpiration = config('jwt.token_expiration', 3600);
        $this->issuer = config('jwt.issuer', 'http://localhost:8001');
    }

    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'user' => $validated['user'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $dpopPublicKey = $validated['dpop_public_key'] ?? null;
        $tokenData = $this->generateToken($user, $dpopPublicKey, false);

        $this->registerSession($user, $tokenData['token'], $request, $dpopPublicKey, false);

        return response()->json([
            'user' => $user,
            'token' => $tokenData['token'],
            'token_type' => $tokenData['token_type'],
            'expires_in' => $tokenData['expires_in'],
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        $validated = $request->validated();

        $loginField = $validated['email'] ?? $validated['user'];
        
        if (!$loginField) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => ['email' => ['The email or username is required.']],
            ], 422);
        }

        $user = User::where('email', $loginField)
            ->orWhere('user', $loginField)
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $dpopPublicKey = $validated['dpop_public_key'] ?? null;
        $rememberMe = $validated['remember_me'] ?? false;
        $tokenData = $this->generateToken($user, $dpopPublicKey, $rememberMe);

        $this->registerSession($user, $tokenData['token'], $request, $dpopPublicKey, $rememberMe);

        $response = response()->json([
            'user' => $user,
            'token' => $tokenData['token'],
            'token_type' => $tokenData['token_type'],
            'expires_in' => $tokenData['expires_in'],
        ]);

        $response->withCookie(cookie('sso_token', $tokenData['token'], $this->tokenExpiration / 60, '/', null, false, false));

        return $response;
    }

    public function refreshToken(RefreshTokenRequest $request): JsonResponse
    {
        $tokenString = $request->validated('token');

        try {
            $token = $this->jwtConfig->parser()->parse($tokenString);

            $constraints = [
                new SignedWith($this->jwtConfig->signer(), $this->jwtConfig->verificationKey()),
                new IssuedBy($this->issuer),
            ];

            if (!$this->jwtConfig->validator()->validate($token, ...$constraints)) {
                return response()->json([
                'message' => 'Invalid token',
            ], 401);
            }

            $userId = $token->claims()->get('sub');
            $user = User::with('roles')->find($userId);

            if (!$user) {
                return response()->json([
                    'message' => 'User not found',
                ], 401);
            }

            $dpopPublicKey = null;
            $rememberMe = false;
            $cnfClaim = $token->claims()->get('cnf');
            if ($cnfClaim && isset($cnfClaim['jkt'])) {
                $existingSession = UserSession::where('user_id', $userId)
                    ->where('token', $tokenString)
                    ->first();
                if ($existingSession) {
                    if ($existingSession->dpop_public_key) {
                        $dpopPublicKey = $existingSession->dpop_public_key;
                    }
                    $rememberMe = $existingSession->remember_me ?? false;
                }
            }

            $newTokenData = $this->generateToken($user, $dpopPublicKey, $rememberMe);

            UserSession::where('user_id', $userId)
                ->where('token', $tokenString)
                ->update(['token' => $newTokenData['token'], 'last_used_at' => now()]);

            return response()->json([
                'token' => $newTokenData['token'],
                'token_type' => $newTokenData['token_type'],
                'expires_in' => $newTokenData['expires_in'],
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'user' => $user->user,
                    'email' => $user->email,
                    'roles' => $user->roles->map(function ($role) {
                        return [
                            'id' => $role->id,
                            'name' => $role->name,
                            'slug' => $role->slug,
                            'description' => $role->description,
                            'is_protected' => $role->is_protected,
                        ];
                    }),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Invalid or expired token: ' . $e->getMessage(),
            ], 401);
        }
    }

    public function logout(Request $request)
    {
        $tokenString = $request->bearerToken();

        if (!$tokenString) {
            $tokenString = $request->cookie('sso_token');
        }

        if (!$tokenString) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        try {
            UserSession::where('token', $tokenString)->delete();
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error closing session: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Session closed successfully',
        ])->withCookie(\Cookie::forget('sso_token'));
    }

    public function me(Request $request): JsonResponse
    {
        $ssoUserId = $request->get('sso_user_id');

        $user = User::with('roles')->find($ssoUserId);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'user' => $user->user,
            'email' => $user->email,
            'roles' => $user->roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'description' => $role->description,
                    'is_protected' => $role->is_protected,
                ];
            }),
        ]);
    }

    public function jwks(Request $request)
    {
        $privateKeyContent = file_get_contents(config('jwt.private_key_path'));
        $keyDetails = openssl_pkey_get_details(openssl_pkey_get_private($privateKeyContent));

        if (!$keyDetails) {
            return response()->json(['error' => 'Unable to get key details'], 500);
        }

        $n = $this->base64UrlEncode($keyDetails['rsa']['n']);
        $e = $this->base64UrlEncode($keyDetails['rsa']['e']);

        return response()->json([
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
        ]);
    }

    public function checkSession(Request $request): JsonResponse
    {
        $ssoUserId = $request->get('sso_user_id');

        if (!$ssoUserId) {
            $tokenString = $request->bearerToken();

            if (!$tokenString) {
                $tokenString = $request->cookie('sso_token');
            }

            if (!$tokenString) {
                return response()->json(['authenticated' => false], 200);
            }

            try {
                $token = $this->jwtConfig->parser()->parse($tokenString);

                $constraints = [
                    new SignedWith($this->jwtConfig->signer(), $this->jwtConfig->verificationKey()),
                    new IssuedBy($this->issuer),
                    new LooseValidAt(new SystemClock(new \DateTimeZone('UTC'))),
                ];

                if (!$this->jwtConfig->validator()->validate($token, ...$constraints)) {
                    return response()->json(['authenticated' => false], 200);
                }

                $ssoUserId = $token->claims()->get('sub');

            } catch (\Exception $e) {
                return response()->json(['authenticated' => false], 200);
            }
        }

        $user = User::with('roles')->find($ssoUserId);

        if (!$user) {
            return response()->json(['authenticated' => false], 200);
        }

        return response()->json([
            'authenticated' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'user' => $user->user,
                'email' => $user->email,
                'roles' => $user->roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'slug' => $role->slug,
                        'description' => $role->description,
                        'is_protected' => $role->is_protected,
                    ];
                }),
            ],
        ]);
    }

    private function generateToken(User $user, ?string $dpopPublicKey = null, bool $rememberMe = false): array
    {
        $now = time();
        $expiresAt = $now + $this->tokenExpiration;

        $builder = $this->jwtConfig->builder()
            ->issuedBy($this->issuer)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt(new \DateTimeImmutable('@' . $now))
            ->expiresAt(new \DateTimeImmutable('@' . $expiresAt))
            ->relatedTo((string) $user->id)
            ->withClaim('user', [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user' => $user->user,
            ]);

        if ($rememberMe) {
            $builder->withClaim('remember_me', true);
        }

        if ($dpopPublicKey) {
            $publicKeyData = json_decode($dpopPublicKey, true);
            if ($publicKeyData) {
                if (!isset($publicKeyData['kty']) || !isset($publicKeyData['n']) || !isset($publicKeyData['e'])) {
                    return response()->json([
                        'message' => 'Validation error',
                        'errors' => ['dpop_public_key' => ['Incomplete JWK: kty, n, and e fields are required']],
                    ], 422);
                }
                if (($publicKeyData['kty'] ?? '') !== 'RSA') {
                    return response()->json([
                        'message' => 'Validation error',
                        'errors' => ['dpop_public_key' => ['Unsupported JWK key type: RSA is required']],
                    ], 422);
                }
                $jkt = $this->generateJWKThumbprint($publicKeyData);
                $builder->withClaim('cnf', [
                    'jkt' => $jkt
                ]);
            }
        }

        $token = $builder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

        return [
            'token' => $token->toString(),
            'token_type' => $dpopPublicKey ? 'DPoP' : 'Bearer',
            'expires_in' => $this->tokenExpiration,
        ];
    }

    private function generateJWKThumbprint(array $jwk): string
    {
        $jwkString = json_encode([
            'e' => $jwk['e'] ?? null,
            'kty' => $jwk['kty'] ?? 'RSA',
            'n' => $jwk['n'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return rtrim(strtr(base64_encode(hash('sha256', $jwkString, true)), '+/', '-_'), '=');
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function registerSession(User $user, string $token, Request $request, ?string $dpopPublicKey = null, bool $rememberMe = false)
    {
        $client = null;
        
        if ($request->client_id) {
            $client = SsoClient::where('client_id', $request->client_id)->first();
        }

        UserSession::create([
            'user_id' => $user->id,
            'sso_client_id' => $client?->id,
            'token' => $token,
            'dpop_public_key' => $dpopPublicKey,
            'remember_me' => $rememberMe,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_used_at' => now(),
        ]);
    }
}