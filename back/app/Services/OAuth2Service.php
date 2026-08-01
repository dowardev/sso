<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Services;

use App\Models\OAuthAuthCode;
use App\Models\SsoClient;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OAuth2Service
{
    private JwtService $jwtService;

    private SessionService $sessionService;

    private int $authCodeExpiration = 600;

    public function __construct(JwtService $jwtService, SessionService $sessionService)
    {
        $this->jwtService = $jwtService;
        $this->sessionService = $sessionService;
    }

    public function createAuthorizationCode(User $user, SsoClient $client, array $data): array
    {
        $code = Str::random(40);

        OAuthAuthCode::create([
            'code' => $code,
            'user_id' => $user->id,
            'sso_client_id' => $client->id,
            'redirect_uri' => $data['redirect_uri'],
            'code_challenge' => $data['code_challenge'] ?? null,
            'code_challenge_method' => $data['code_challenge_method'] ?? null,
            'code_verifier' => $data['code_verifier'] ?? null,
            'expires_at' => now()->addSeconds($this->authCodeExpiration),
        ]);

        return [
            'code' => $code,
            'state' => $data['state'] ?? null,
        ];
    }

    public function validateClientAndGet(string $clientId): ?SsoClient
    {
        $client = SsoClient::where('client_id', $clientId)->first();

        if (! $client || ! $client->active) {
            return null;
        }

        return $client;
    }

    public function handleClientCredentials(SsoClient $client, array $data): ?array
    {
        $grantTypes = $client->grant_types ?? [];

        if (! in_array('client_credentials', $grantTypes)) {
            return null;
        }

        if (! $client->client_secret) {
            return null;
        }

        if (! isset($data['client_secret']) || $data['client_secret'] !== $client->client_secret) {
            return null;
        }

        return $this->jwtService->generateClientToken($client->client_id, 'client_credentials');
    }

    public function handleAuthorizationCode(Request $request, SsoClient $client, array $data): ?array
    {
        $authCode = OAuthAuthCode::where('code', $data['code'])
            ->where('sso_client_id', $client->id)
            ->first();

        if (! $authCode) {
            return null;
        }

        if ($authCode->isExpired()) {
            return null;
        }

        if ($authCode->isUsed()) {
            return null;
        }

        if ($authCode->redirect_uri !== $data['redirect_uri']) {
            return null;
        }

        if ($authCode->code_challenge) {
            $codeVerifier = $authCode->code_verifier ?? ($data['code_verifier'] ?? null);

            if (! $codeVerifier) {
                return null;
            }

            $method = $authCode->code_challenge_method ?? 'S256';
            $challengeFromVerifier = $this->computeCodeChallenge($codeVerifier, $method);

            if ($challengeFromVerifier !== $authCode->code_challenge) {
                return null;
            }
        } else {
            return null;
        }

        $authCode->update(['used_at' => now()]);

        $user = User::with('roles')->find($authCode->user_id);

        if (! $user) {
            return null;
        }

        $dpopPublicKey = $data['dpop_public_key'] ?? null;
        $tokenData = $this->jwtService->generateToken($user, $dpopPublicKey);

        $this->sessionService->registerSession($user, $tokenData['token'], $request, $dpopPublicKey, false, $client);

        return [
            'token' => $tokenData['token'],
            'token_type' => $tokenData['token_type'],
            'expires_in' => $tokenData['expires_in'],
            'user' => array_merge($user->toArray(), ['roles' => $user->roles->toArray()]),
        ];
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
