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

    private AuthService $authService;

    private int $authCodeExpiration = 600;

    public function __construct(JwtService $jwtService, SessionService $sessionService, AuthService $authService)
    {
        $this->jwtService = $jwtService;
        $this->sessionService = $sessionService;
        $this->authService = $authService;
    }

    public function createAuthorizationCode(User $user, SsoClient $client, array $data, ?string $scope = null, ?string $nonce = null): array
    {
        $code = Str::random(40);

        OAuthAuthCode::create([
            'code' => $code,
            'user_id' => $user->id,
            'sso_client_id' => $client->id,
            'redirect_uri' => $data['redirect_uri'],
            'scope' => $scope,
            'nonce' => $nonce,
            'code_challenge' => $data['code_challenge'] ?? null,
            'code_challenge_method' => $data['code_challenge_method'] ?? null,
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

    /**
     * Normalize the raw `scope` value the client sent at /oauth/authorize.
     *
     * Returns the space-separated scope string to persist on the auth code
     * (and to emit in the access token's `scope` claim), or an error message
     * if the request is not satisfiable. Behavior:
     *
     *   - null / empty  → grants every scope the client is allowed to request
     *     (its full allowed_scopes, falling back to the OIDC core set when
     *     the column is null). Preserves back-compat for clients that never
     *     sent a scope.
     *   - non-empty     → must be a subset of the client's allowed_scopes.
     *     Each token is checked; unknown / disallowed tokens fail closed with
     *     the offending token in the error so the caller can fix their config
     *     without a guess.
     *
     * @return array{0: ?string, 1: ?string} Tuple of [normalized scope, error message].
     *                                       Exactly one of them is non-null.
     */
    public function resolveScope(SsoClient $client, ?string $rawScope): array
    {
        $allowed = $client->effectiveAllowedScopes();
        $allowedMap = array_flip($allowed);

        if ($rawScope === null || trim($rawScope) === '') {
            return [implode(' ', $allowed), null];
        }

        $requested = preg_split('/\s+/', trim($rawScope), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (empty($requested)) {
            return [implode(' ', $allowed), null];
        }

        $unknown = [];
        foreach ($requested as $token) {
            if (! isset($allowedMap[$token])) {
                $unknown[] = $token;
            }
        }

        if (! empty($unknown)) {
            return [
                null,
                "El scope solicitado contiene tokens no permitidos para el cliente '{$client->client_id}': "
                    .implode(', ', $unknown)
                    .'. Scopes permitidos: '.implode(', ', $allowed).'.',
            ];
        }

        // Preserve the client's order so the same requested scope always
        // maps to the same persisted string, which keeps tests and
        // log inspection deterministic.
        $normalized = array_values(array_intersect($allowed, $requested));

        return [implode(' ', $normalized), null];
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

        if (! $authCode->code_challenge) {
            // A confidential client (one with a client_secret in the
            // SsoClient row) is allowed to skip PKCE per RFC 7636 §1;
            // the client_secret is its own defense. A public client
            // must have code_challenge; the controller rejected
            // public-client requests without it, but the auth code
            // could still pre-date that check (or be replayed from a
            // pre-migration row), so the second layer of defense is
            // here at the token exchange.
            if ($client->isPublic()) {
                return null;
            }

            $codeVerifier = null;
            $method = null;
        } else {
            $codeVerifier = $data['code_verifier'] ?? null;

            if (! $codeVerifier) {
                return null;
            }

            $method = $authCode->code_challenge_method ?? 'S256';
            $challengeFromVerifier = $this->computeCodeChallenge($codeVerifier, $method);

            if (! hash_equals($authCode->code_challenge, $challengeFromVerifier)) {
                return null;
            }
        }

        $authCode->update(['used_at' => now()]);

        $user = User::with('roles')->find($authCode->user_id);

        if (! $user) {
            return null;
        }

        $dpopPublicKey = $data['dpop_public_key'] ?? null;
        $tokenData = $this->jwtService->generateToken($user, $dpopPublicKey, false, null, $authCode->scope, $client->client_id);

        $this->sessionService->registerSession($user, $tokenData['token'], $request, $dpopPublicKey, false, $client);

        $refreshTokenData = $this->authService->generateRefreshToken(
            $user,
            $request,
            false,
            $this->authService->jktFromDpopPublicKey($dpopPublicKey),
            $authCode->scope,
            $client->client_id,
        );

        $response = [
            'token' => $tokenData['token'],
            'token_type' => $tokenData['token_type'],
            'expires_in' => $tokenData['expires_in'],
            'refresh_token' => $refreshTokenData['plain'],
            'user' => array_merge($user->toArray(), ['roles' => $user->roles->toArray()]),
        ];

        if (isset($tokenData['scope']) && $tokenData['scope'] !== null && $tokenData['scope'] !== '') {
            $response['scope'] = $tokenData['scope'];
        }

        // LBDS-303: when the granted scope includes `openid`, this is an
        // OIDC RP-initiated flow, so we also issue an id_token alongside
        // the access token. The id_token is what the RP verifies at login
        // (using the JWKS); the access token is what the RP presents on
        // every API call. They are signed by the same key but carry
        // different `aud` values: id_token -> client_id, access token ->
        // user id. The per-client claim_overrides are applied here, on
        // top of the standard OIDC claim set.
        $grantedScopes = $this->parseGrantedScopes($authCode->scope);
        if (in_array('openid', $grantedScopes, true)) {
            $overrides = $client->effectiveClaimOverrides();
            $response['id_token'] = $this->jwtService->generateIdToken(
                $user,
                $client,
                $authCode->nonce,
                $grantedScopes,
                now()->getTimestamp(),
                $overrides['extra_claims'],
            );
        }

        return $response;
    }

    public function handleRefreshToken(SsoClient $client, array $data, Request $request): ?array
    {
        $plainRefreshToken = $data['refresh_token'] ?? $data['refreshToken'] ?? null;

        if (! $plainRefreshToken) {
            return null;
        }

        return $this->authService->refreshAccessToken($plainRefreshToken, $request, true);
    }

    private function computeCodeChallenge(string $codeVerifier, string $method): string
    {
        if ($method === 'S256') {
            $hash = hash('sha256', $codeVerifier, true);

            return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
        }

        return $codeVerifier;
    }

    /**
     * Parse the space-separated scope string persisted on the auth code
     * into a list of individual scope tokens. Empty / null input yields
     * an empty list, so callers can safely use `in_array` without null
     * guards.
     *
     * @return array<int, string>
     */
    private function parseGrantedScopes(?string $scope): array
    {
        if (! is_string($scope) || trim($scope) === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }
}
