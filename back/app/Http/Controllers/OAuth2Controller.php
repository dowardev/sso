<?php

namespace App\Http\Controllers;

use App\Models\OAuthAuthCode;
use App\Models\SsoClient;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;

use App\Http\Requests\OAuth\AuthorizeRequest;
use App\Http\Requests\OAuth\TokenRequest;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

class OAuth2Controller extends Controller
{
    private Configuration $jwtConfig;
    private int $tokenExpiration;
    private string $issuer;
    private int $authCodeExpiration = 600;

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

    public function authorize(AuthorizeRequest $request)
    {
        $validated = $request->validated();

        $client = SsoClient::where('client_id', $validated['client_id'])->first();

        if (!$client->active) {
            return response()->json([
                'message' => 'Client is disabled',
            ], 403);
        }

        if ($client->redirect_url && $validated['redirect_uri'] !== $client->redirect_url) {
            return response()->json([
                'message' => 'redirect_uri not allowed',
            ], 400);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json([
                'message' => 'Not authenticated',
            ], 401);
        }

        $code = Str::random(40);

        OAuthAuthCode::create([
            'code' => $code,
            'user_id' => $user->id,
            'sso_client_id' => $client->id,
            'redirect_uri' => $validated['redirect_uri'],
            'code_challenge' => $validated['code_challenge'] ?? null,
            'code_challenge_method' => $validated['code_challenge_method'] ?? null,
            'code_verifier' => $validated['code_verifier'] ?? null,
            'expires_at' => now()->addSeconds($this->authCodeExpiration),
        ]);

        return response()->json([
            'code' => $code,
            'state' => $validated['state'] ?? null,
        ]);
    }

    public function token(TokenRequest $request)
    {
        $validated = $request->validated();

        $client = SsoClient::where('client_id', $validated['client_id'])->first();

        if (!$client->active) {
            return response()->json([
                'message' => 'Client is disabled',
            ], 403);
        }

        if ($validated['grant_type'] === 'client_credentials') {
            return $this->handleClientCredentials($validated, $client);
        }

        return $this->handleAuthorizationCode($request, $validated, $client);
    }

    private function handleClientCredentials(array $validated, SsoClient $client)
    {
        $grantTypes = $client->grant_types ?? [];

        if (!in_array('client_credentials', $grantTypes)) {
            return response()->json([
                'message' => 'This client does not have the client_credentials grant type',
            ], 403);
        }

        if (!$client->client_secret) {
            return response()->json([
                'message' => 'client_secret is required for client_credentials',
            ], 400);
        }

        if (!isset($validated['client_secret']) || $validated['client_secret'] !== $client->client_secret) {
            return response()->json([
                'message' => 'Invalid client_secret',
            ], 401);
        }

        $tokenData = $this->generateClientToken($client);

        return response()->json([
            'token' => $tokenData['token'],
            'token_type' => $tokenData['token_type'],
            'expires_in' => $tokenData['expires_in'],
        ]);
    }

    private function handleAuthorizationCode(Request $request, array $validated, SsoClient $client)
    {
        if ($client->redirect_url && $validated['redirect_uri'] !== $client->redirect_url) {
            return response()->json([
                'message' => 'redirect_uri not allowed',
            ], 400);
        }

        $providedSecret = $validated['client_secret'] ?? null;
        if ($client->client_secret && $providedSecret) {
            if ($providedSecret !== $client->client_secret) {
                return response()->json([
                    'message' => 'Invalid client_secret',
                ], 401);
            }
        }

        $authCode = OAuthAuthCode::where('code', $validated['code'])
            ->where('sso_client_id', $client->id)
            ->first();

        if (!$authCode) {
            return response()->json([
                'message' => 'Invalid authorization code',
            ], 400);
        }

        if ($authCode->isExpired()) {
            return response()->json([
                'message' => 'Authorization code has expired',
            ], 400);
        }

        if ($authCode->isUsed()) {
            return response()->json([
                'message' => 'Authorization code has already been used',
            ], 400);
        }

        if ($authCode->redirect_uri !== $validated['redirect_uri']) {
            return response()->json([
                'message' => 'redirect_uri does not match',
            ], 400);
        }

        if ($authCode->code_challenge) {
            $codeVerifier = $authCode->code_verifier ?? $validated['code_verifier'];

            if (!$codeVerifier) {
                return response()->json([
                    'message' => 'code_verifier is required',
                ], 400);
            }

            $method = $authCode->code_challenge_method ?? 'S256';
            $challengeFromVerifier = $this->computeCodeChallenge(
                $codeVerifier,
                $method
            );

            if ($challengeFromVerifier !== $authCode->code_challenge) {
                    return response()->json([
                        'message' => 'Invalid code_verifier',
                    ], 400);
                }
            } else {
                return response()->json([
                    'message' => 'PKCE required: code_challenge not found',
                ], 400);
            }

        $authCode->update(['used_at' => now()]);

        $user = User::with('roles')->find($authCode->user_id);

        if (!$user) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        $dpopPublicKey = $validated['dpop_public_key'] ?? null;
        $tokenData = $this->generateToken($user, $dpopPublicKey);

        $this->registerSession($user, $tokenData['token'], $request, $client, $dpopPublicKey);

        $userArray = $user->toArray();
        $userArray['roles'] = $user->roles->toArray();

        return response()->json([
            'token' => $tokenData['token'],
            'token_type' => $tokenData['token_type'],
            'expires_in' => $tokenData['expires_in'],
            'user' => $userArray,
        ]);
    }

    private function generateClientToken(SsoClient $client): array
    {
        $now = time();
        $expiresAt = $now + $this->tokenExpiration;

        $builder = $this->jwtConfig->builder()
            ->issuedBy($this->issuer)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt(new \DateTimeImmutable('@' . $now))
            ->expiresAt(new \DateTimeImmutable('@' . $expiresAt))
            ->relatedTo($client->client_id)
            ->withClaim('grant_type', 'client_credentials')
            ->withClaim('client_id', $client->client_id);

        $token = $builder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

        return [
            'token' => $token->toString(),
            'token_type' => 'Bearer',
            'expires_in' => $this->tokenExpiration,
        ];
    }

    private function generateToken(User $user, ?string $dpopPublicKey = null): array
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

        if ($dpopPublicKey) {
            $publicKeyData = json_decode($dpopPublicKey, true);
            if ($publicKeyData) {
                if (!isset($publicKeyData['kty']) || !isset($publicKeyData['n']) || !isset($publicKeyData['e'])) {
                    return [
                        'token' => '',
                        'token_type' => 'Bearer',
                        'expires_in' => $this->tokenExpiration,
                    ];
                }
                if (($publicKeyData['kty'] ?? '') !== 'RSA') {
                    return [
                        'token' => '',
                        'token_type' => 'Bearer',
                        'expires_in' => $this->tokenExpiration,
                    ];
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

    private function registerSession(User $user, string $token, Request $request, SsoClient $client, ?string $dpopPublicKey = null)
    {
        UserSession::create([
            'user_id' => $user->id,
            'sso_client_id' => $client->id,
            'token' => $token,
            'dpop_public_key' => $dpopPublicKey,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_used_at' => now(),
        ]);
    }

    private function computeCodeChallenge(string $codeVerifier, string $method): string
    {
        if ($method === 'S256') {
            $hash = hash('sha256', $codeVerifier, true);
            return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
        }

        return $codeVerifier;
    }
}