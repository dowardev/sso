<?php

namespace App\Http\Controllers;

use App\Models\OAuthAuthCode;
use App\Models\SsoClient;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
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

    public function authorize(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|string|exists:sso_clients,client_id',
            'redirect_uri' => 'required|string',
            'state' => 'nullable|string',
            'code_challenge' => 'nullable|string',
            'code_challenge_method' => 'nullable|string|in:S256,plain',
        ]);

        $client = SsoClient::where('client_id', $validated['client_id'])->first();

        if (!$client->active) {
            return response()->json([
                'message' => 'Cliente desactivado',
            ], 403);
        }

        if ($client->redirect_url && $validated['redirect_uri'] !== $client->redirect_url) {
            return response()->json([
                'message' => 'redirect_uri no permitida',
            ], 400);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json([
                'message' => 'No autenticado',
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
            'expires_at' => now()->addSeconds($this->authCodeExpiration),
        ]);

        return response()->json([
            'code' => $code,
            'state' => $validated['state'] ?? null,
        ]);
    }

    public function token(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'client_id' => 'required|string|exists:sso_clients,client_id',
            'client_secret' => 'nullable|string',
            'redirect_uri' => 'required|string',
            'dpop_public_key' => 'nullable|json',
            'code_verifier' => 'required|string|min:43|max:128',
        ]);

        $client = SsoClient::where('client_id', $validated['client_id'])->first();

        if (!$client->active) {
            return response()->json([
                'message' => 'Cliente desactivado',
            ], 403);
        }

        if ($client->redirect_url && $validated['redirect_uri'] !== $client->redirect_url) {
            return response()->json([
                'message' => 'redirect_uri no permitida',
            ], 400);
        }

        if ($client->client_secret && $validated['client_secret']) {
            if ($validated['client_secret'] !== $client->client_secret) {
                return response()->json([
                    'message' => 'client_secret inválido',
                ], 401);
            }
        }

        $authCode = OAuthAuthCode::where('code', $validated['code'])
            ->where('sso_client_id', $client->id)
            ->first();

        if (!$authCode) {
            return response()->json([
                'message' => 'Código de autorización inválido',
            ], 400);
        }

        if ($authCode->isExpired()) {
            return response()->json([
                'message' => 'Código de autorización expirado',
            ], 400);
        }

        if ($authCode->isUsed()) {
            return response()->json([
                'message' => 'Código de autorización ya utilizado',
            ], 400);
        }

        if ($authCode->redirect_uri !== $validated['redirect_uri']) {
            return response()->json([
                'message' => 'redirect_uri no coincide',
            ], 400);
        }

        if ($authCode->code_challenge) {
            $method = $authCode->code_challenge_method ?? 'S256';
            $challengeFromVerifier = $this->computeCodeChallenge(
                $validated['code_verifier'],
                $method
            );

            if ($challengeFromVerifier !== $authCode->code_challenge) {
                return response()->json([
                    'message' => 'code_verifier inválido',
                ], 400);
            }
        } else {
            return response()->json([
                'message' => 'PKCE requerido: code_challenge no encontrado',
            ], 400);
        }

        $authCode->update(['used_at' => now()]);

        $user = User::with('roles')->find($authCode->user_id);

        if (!$user) {
            return response()->json([
                'message' => 'Usuario no encontrado',
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
            'kty' => $jwk['kty'] ?? 'RSA',
            'n' => $jwk['n'] ?? null,
            'e' => $jwk['e'] ?? null,
        ]);

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