<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Http\Requests\AuthorizeRequest;
use App\Http\Requests\TokenRequest;
use App\Models\OAuthAuthCode;
use App\Models\OauthClientAuthorization;
use App\Models\Setting;
use App\Models\SsoClient;
use App\Models\UserActivityLog;
use App\Services\ActivityLogService;
use App\Services\OAuth2Service;
use App\Support\ApiError;
use App\Support\CorsOrigins;
use App\Support\OriginNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OAuth2Controller extends Controller
{
    private OAuth2Service $oauth2Service;

    private ActivityLogService $activityLogService;

    public function __construct(OAuth2Service $oauth2Service, ActivityLogService $activityLogService)
    {
        $this->oauth2Service = $oauth2Service;
        $this->activityLogService = $activityLogService;
    }

    public function authorize(AuthorizeRequest $request): JsonResponse
    {
        $client = $this->oauth2Service->validateClientAndGet($request->client_id);

        if (! $client) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_NOT_REGISTERED, 'The client is disabled or not found'),
                403
            );
        }

        if (! $client->redirect_url) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_MISCONFIGURED, "El cliente '{$client->client_id}' no tiene redirect_url configurada, así que el SSO no puede validar el callback."),
                400
            );
        }

        // A challenge without a method is half-formed PKCE: the verifier
        // cannot recompute the challenge without knowing the hash. We
        // reject that here, even for confidential clients, because a
        // code with a challenge but no method is unusable and the
        // silent "best effort" path would leave a stuck auth code
        // behind.
        if ($request->code_challenge && ! $request->code_challenge_method) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_MISCONFIGURED, 'code_challenge_method is required when code_challenge is sent.'),
                400
            );
        }

        // PKCE is required for public clients (no client_secret). For
        // confidential clients, omitting it is RFC 7636-compliant: the
        // client_secret is its own defense. The check is here, after
        // the client lookup, because the rule depends on the client's
        // own configuration, which the FormRequest cannot see.
        if ($client->isPublic() && ! $request->code_challenge) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_MISCONFIGURED, 'PKCE is required for public clients: include code_challenge + code_challenge_method (RFC 7636).'),
                400
            );
        }

        if ($request->redirect_uri !== $client->redirect_url) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_REDIRECT_URI_NOT_ALLOWED, 'redirect_uri no permitida'),
                400
            );
        }

        $originMismatch = $this->describeRedirectOriginMismatch($client, $request->redirect_uri);

        if ($originMismatch !== null) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_MISCONFIGURED, $originMismatch),
                400
            );
        }

        if ($this->isSslRequiredViolation($client->redirect_url)) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_MISCONFIGURED, "The client's redirect_url must use HTTPS when the require-SSL option is enabled"),
                400
            );
        }

        $user = $request->user();
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
        }

        $hasAuthorized = OauthClientAuthorization::where('user_id', $user->id)
            ->where('sso_client_id', $client->id)
            ->where('status', 'authorized')
            ->exists();

        [$scope, $scopeError] = $this->oauth2Service->resolveScope($client, $request->scope);

        if ($scopeError !== null) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_MISCONFIGURED, $scopeError),
                400
            );
        }

        if (! $hasAuthorized) {
            return response()->json([
                'authorization_required' => true,
                'client' => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'client_id' => $client->client_id,
                ],
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'user' => $user->user,
                ],
                'scope' => $scope,
            ]);
        }

        $result = $this->oauth2Service->createAuthorizationCode($user, $client, $request->validated(), $scope, $request->nonce);

        return response()->json($result);
    }

    public function approveAuthorization(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string|exists:sso_clients,client_id',
            'redirect_uri' => 'required|string',
            'state' => 'nullable|string',
            'response_type' => 'nullable|string',
            'scope' => 'nullable|string',
            'nonce' => 'nullable|string|max:255',
            'code_challenge' => 'nullable|string',
            'code_challenge_method' => 'nullable|string|in:S256,plain',
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
        }

        $client = $this->oauth2Service->validateClientAndGet($request->client_id);
        if (! $client) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_NOT_REGISTERED, 'The client was not found or is inactive'),
                404
            );
        }

        if (! $client->redirect_url) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_MISCONFIGURED, "El cliente '{$client->client_id}' no tiene redirect_url configurada, así que el SSO no puede validar el callback."),
                400
            );
        }

        if ($request->code_challenge && ! $request->code_challenge_method) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_MISCONFIGURED, 'code_challenge_method is required when code_challenge is sent.'),
                400
            );
        }

        if ($client->isPublic() && ! $request->code_challenge) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_MISCONFIGURED, 'PKCE is required for public clients: include code_challenge + code_challenge_method (RFC 7636).'),
                400
            );
        }

        if ($request->redirect_uri !== $client->redirect_url) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_REDIRECT_URI_NOT_ALLOWED, 'redirect_uri no permitida'),
                400
            );
        }

        $originMismatch = $this->describeRedirectOriginMismatch($client, $request->redirect_uri);

        if ($originMismatch !== null) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_MISCONFIGURED, $originMismatch),
                400
            );
        }

        [$scope, $scopeError] = $this->oauth2Service->resolveScope($client, $request->scope);

        if ($scopeError !== null) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_MISCONFIGURED, $scopeError),
                400
            );
        }

        OauthClientAuthorization::updateOrCreate(
            ['user_id' => $user->id, 'sso_client_id' => $client->id],
            ['status' => 'authorized', 'updated_at' => now()]
        );

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_AUTHORIZATION_GRANTED,
            'Authorization granted to '.$client->name,
            ['client_id' => $client->id, 'client_name' => $client->name],
            $request,
        );

        $result = $this->oauth2Service->createAuthorizationCode($user, $client, $request->only([
            'redirect_uri',
            'state',
            'code_challenge',
            'code_challenge_method',
        ]), $scope, $request->nonce);

        return response()->json($result);
    }

    public function denyAuthorization(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string|exists:sso_clients,client_id',
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
        }

        $client = $this->oauth2Service->validateClientAndGet($request->client_id);
        if (! $client) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_NOT_REGISTERED, 'The client was not found or is inactive'),
                404
            );
        }

        OauthClientAuthorization::updateOrCreate(
            ['user_id' => $user->id, 'sso_client_id' => $client->id],
            ['status' => 'denied', 'updated_at' => now()]
        );

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_AUTHORIZATION_DENIED,
            'Authorization denied to '.$client->name,
            ['client_id' => $client->id, 'client_name' => $client->name],
            $request,
        );

        return response()->json(ApiError::success(ApiError::SUCCESS_AUTHORIZATION_DENIED, 'Authorization denied.'));
    }

    public function revokeAuthorization(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string|exists:sso_clients,client_id',
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
        }

        $client = $this->oauth2Service->validateClientAndGet($request->client_id);
        if (! $client) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_NOT_REGISTERED, 'The client was not found or is inactive'),
                404
            );
        }

        $deleted = OauthClientAuthorization::where('user_id', $user->id)
            ->where('sso_client_id', $client->id)
            ->delete();

        if (! $deleted) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_CREDENTIALS_NOT_FOUND, 'No authorization was found to revoke'),
                404
            );
        }

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_AUTHORIZATION_REVOKED,
            'Access revoked to '.$client->name,
            ['client_id' => $client->id, 'client_name' => $client->name],
            $request,
        );

        return response()->json(ApiError::success(ApiError::SUCCESS_ACCESS_REVOKED, 'Access revoked successfully.'));
    }

    public function userAuthorizations(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
        }

        $perPage = min((int) $request->get('per_page', 20), 50);
        $authorizations = OauthClientAuthorization::with('client')
            ->where('user_id', $user->id)
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $authorizations->map(function ($auth) {
                return [
                    'id' => $auth->id,
                    'status' => $auth->status,
                    'updated_at' => $auth->updated_at,
                    'client' => $auth->client ? [
                        'id' => $auth->client->id,
                        'name' => $auth->client->name,
                        'client_id' => $auth->client->client_id,
                    ] : null,
                ];
            }),
            'pagination' => [
                'total' => $authorizations->total(),
                'per_page' => $authorizations->perPage(),
                'current_page' => $authorizations->currentPage(),
                'last_page' => $authorizations->lastPage(),
            ],
        ]);
    }

    public function clientAuthorizations(Request $request, int $clientId): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'No autenticado'),
                401
            );
        }

        // Verify user has access to this client (admin or owner)
        $client = SsoClient::find($clientId);
        if (! $client) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_USER_NOT_FOUND, 'Cliente no encontrado'),
                404
            );
        }

        $authorizations = OauthClientAuthorization::with('user')
            ->where('sso_client_id', $clientId)
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(function ($auth) {
                return [
                    'id' => $auth->id,
                    'status' => $auth->status,
                    'updated_at' => $auth->updated_at,
                    'user' => $auth->user ? [
                        'id' => $auth->user->id,
                        'name' => $auth->user->name,
                        'user' => $auth->user->user,
                        'email' => $auth->user->email,
                    ] : null,
                ];
            });

        return response()->json($authorizations);
    }

    public function token(TokenRequest $request): JsonResponse
    {
        $client = $this->oauth2Service->validateClientAndGet($request->client_id);

        if (! $client) {
            return response()->json(
                ApiError::topLevel(ApiError::CLIENT_NOT_REGISTERED, 'The client is disabled or not found'),
                403
            );
        }

        if ($request->grant_type === 'client_credentials') {
            $result = $this->oauth2Service->handleClientCredentials($client, $request->validated());

            if (! $result) {
                return response()->json(
                    ApiError::topLevel(ApiError::CLIENT_INVALID_CREDENTIALS, 'client_credentials is not allowed or the credentials are invalid'),
                    403
                );
            }

            return response()->json($result);
        }

        if ($request->grant_type === 'refresh_token') {
            $result = $this->oauth2Service->handleRefreshToken($client, $request->validated(), $request);

            if (! $result) {
                return response()->json(
                    ApiError::topLevel(ApiError::AUTH_INVALID_CREDENTIALS, 'The refresh token is invalid, expired or revoked'),
                    401
                );
            }

            return response()->json($result);
        }

        $result = $this->oauth2Service->handleAuthorizationCode($request, $client, $request->validated());

        if (! $result) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_CREDENTIALS_NOT_FOUND, 'The authorization code is invalid or expired'),
                400
            );
        }

        if ($request->grant_type === 'authorization_code') {
            $authCode = OAuthAuthCode::where('code', $request->code)->first();
            $user = $authCode?->user;

            if ($user) {
                $this->activityLogService->logFromRequest(
                    $user,
                    UserActivityLog::ACTION_TOKEN_CREATED,
                    'Access granted to '.$client->name,
                    ['client_id' => $client->id, 'client_name' => $client->name],
                    $request,
                );
            }
        }

        return response()->json($result);
    }

    /**
     * Detect a client whose redirect_uri origin is missing from its own
     * allowed_origins list. Without this check the flow emits a code, the
     * browser follows the redirect, and the app's token request is then
     * blocked by CORS — the user ends up logged into the SSO but not into the
     * app, with nothing explaining why.
     */
    private function describeRedirectOriginMismatch(SsoClient $client, ?string $redirectUri): ?string
    {
        // A client holding a secret may exchange the code server-to-server, where
        // CORS never applies: allowed_origins is the API caller allowlist and says
        // nothing about the redirect host. Only public clients are expected to run
        // the exchange from the browser, which is when this check has real meaning.
        //
        // Known limit, accepted on purpose: this is an inference, not a certainty.
        // A public client that relays the exchange from its own backend is still
        // rejected here, and a confidential client exchanging in the browser skips
        // the check (to be blocked later by CORS at token time). The false positive
        // is preferred: it surfaces as a visible 400 the developer can fix in a
        // minute, whereas dropping the check brings back the silent
        // half-logged-in user this validation exists to prevent.
        if ($this->isConfidentialClient($client)) {
            return null;
        }

        $allowedOrigins = $this->effectiveCorsOrigins($client);

        if (empty($allowedOrigins) || ! $redirectUri) {
            return null;
        }

        if (OriginNormalizer::matches($redirectUri, $allowedOrigins)) {
            return null;
        }

        $redirectOrigin = OriginNormalizer::normalize($redirectUri);

        // Native apps redirect through a custom scheme and never hit CORS.
        if ($redirectOrigin === null) {
            return null;
        }

        $allowed = implode(', ', OriginNormalizer::normalizeAll($allowedOrigins));

        return "The client's redirect_uri '{$client->client_id}' is not within its allowed_origins, "
            .'so the browser will block the token exchange (CORS). '
            ."Redirect origin: {$redirectOrigin}. "
            ."Allowed origins: {$allowed}. "
            .'Add that origin to the client allowed_origins or fix its redirect_url.';
    }

    private function isConfidentialClient(SsoClient $client): bool
    {
        return is_string($client->client_secret) && $client->client_secret !== '';
    }

    /**
     * The origins HandleDynamicCors actually honours for this client. Resolved
     * through the same service the middleware uses, so the two cannot disagree.
     *
     * @return array<int, string>
     */
    private function effectiveCorsOrigins(SsoClient $client): array
    {
        return array_values(array_filter(
            CorsOrigins::forClient($client->client_id),
            'is_string'
        ));
    }

    private function isSslRequiredViolation(string $redirectUrl): bool
    {
        if (! Setting::get('require_ssl', false)) {
            return false;
        }

        if (str_starts_with($redirectUrl, 'https://')) {
            return false;
        }

        $host = parse_url($redirectUrl, PHP_URL_HOST);
        if (! $host) {
            return false;
        }

        $defaultExclusions = ['localhost', '127.0.0.1', '::1'];
        if (in_array($host, $defaultExclusions)) {
            return false;
        }

        $customExclusions = Setting::get('ssl_exclusions', []);
        if (is_array($customExclusions) && in_array($host, $customExclusions)) {
            return false;
        }

        return true;
    }
}
