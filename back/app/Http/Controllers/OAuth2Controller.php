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
            return response()->json([
                'message' => 'Cliente desactivado o no encontrado',
            ], 403);
        }

        if ($client->redirect_url && $request->redirect_uri !== $client->redirect_url) {
            return response()->json([
                'message' => 'redirect_uri no permitida',
            ], 400);
        }

        if ($client->redirect_url && $this->isSslRequiredViolation($client->redirect_url)) {
            return response()->json([
                'message' => 'El redirect_url del cliente debe usar HTTPS cuando la opción de requerir SSL está activa',
            ], 400);
        }

        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'No autenticado',
            ], 401);
        }

        $hasAuthorized = OauthClientAuthorization::where('user_id', $user->id)
            ->where('sso_client_id', $client->id)
            ->where('status', 'authorized')
            ->exists();

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
            ]);
        }

        $result = $this->oauth2Service->createAuthorizationCode($user, $client, $request->validated());

        return response()->json($result);
    }

    public function approveAuthorization(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string|exists:sso_clients,client_id',
            'redirect_uri' => 'required|string',
            'state' => 'nullable|string',
            'response_type' => 'nullable|string',
            'code_challenge' => 'nullable|string',
            'code_challenge_method' => 'nullable|string|in:S256,plain',
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'No autenticado',
            ], 401);
        }

        $client = $this->oauth2Service->validateClientAndGet($request->client_id);
        if (! $client) {
            return response()->json([
                'message' => 'Cliente no encontrado o inactivo',
            ], 404);
        }

        OauthClientAuthorization::updateOrCreate(
            ['user_id' => $user->id, 'sso_client_id' => $client->id],
            ['status' => 'authorized', 'updated_at' => now()]
        );

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_AUTHORIZATION_GRANTED,
            'Autorización concedida a '.$client->name,
            ['client_id' => $client->id, 'client_name' => $client->name],
            $request,
        );

        $result = $this->oauth2Service->createAuthorizationCode($user, $client, $request->only([
            'redirect_uri',
            'state',
            'code_challenge',
            'code_challenge_method',
        ]));

        return response()->json($result);
    }

    public function denyAuthorization(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string|exists:sso_clients,client_id',
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'No autenticado',
            ], 401);
        }

        $client = $this->oauth2Service->validateClientAndGet($request->client_id);
        if (! $client) {
            return response()->json([
                'message' => 'Cliente no encontrado o inactivo',
            ], 404);
        }

        OauthClientAuthorization::updateOrCreate(
            ['user_id' => $user->id, 'sso_client_id' => $client->id],
            ['status' => 'denied', 'updated_at' => now()]
        );

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_AUTHORIZATION_DENIED,
            'Autorización denegada a '.$client->name,
            ['client_id' => $client->id, 'client_name' => $client->name],
            $request,
        );

        return response()->json([
            'message' => 'Autorización denegada',
        ]);
    }

    public function revokeAuthorization(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string|exists:sso_clients,client_id',
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'No autenticado',
            ], 401);
        }

        $client = $this->oauth2Service->validateClientAndGet($request->client_id);
        if (! $client) {
            return response()->json([
                'message' => 'Cliente no encontrado o inactivo',
            ], 404);
        }

        $deleted = OauthClientAuthorization::where('user_id', $user->id)
            ->where('sso_client_id', $client->id)
            ->delete();

        if (! $deleted) {
            return response()->json([
                'message' => 'No se encontró autorización para revocar',
            ], 404);
        }

        $this->activityLogService->logFromRequest(
            $user,
            UserActivityLog::ACTION_AUTHORIZATION_REVOKED,
            'Acceso revocado a '.$client->name,
            ['client_id' => $client->id, 'client_name' => $client->name],
            $request,
        );

        return response()->json([
            'message' => 'Acceso revocado correctamente',
        ]);
    }

    public function userAuthorizations(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'No autenticado',
            ], 401);
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
            return response()->json([
                'message' => 'No autenticado',
            ], 401);
        }

        // Verify user has access to this client (admin or owner)
        $client = SsoClient::find($clientId);
        if (! $client) {
            return response()->json([
                'message' => 'Cliente no encontrado',
            ], 404);
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
            return response()->json([
                'message' => 'Cliente desactivado o no encontrado',
            ], 403);
        }

        if ($request->grant_type === 'client_credentials') {
            $result = $this->oauth2Service->handleClientCredentials($client, $request->validated());

            if (! $result) {
                return response()->json([
                    'message' => 'client_credentials no permitido o credenciales inválidas',
                ], 403);
            }

            return response()->json($result);
        }

        if ($request->grant_type === 'refresh_token') {
            $result = $this->oauth2Service->handleRefreshToken($client, $request->validated(), $request);

            if (! $result) {
                return response()->json([
                    'message' => 'Refresh token inválido, expirado o revocado',
                ], 401);
            }

            return response()->json($result);
        }

        $result = $this->oauth2Service->handleAuthorizationCode($request, $client, $request->validated());

        if (! $result) {
            return response()->json([
                'message' => 'Código de autorización inválido o expirado',
            ], 400);
        }

        if ($request->grant_type === 'authorization_code') {
            $authCode = OAuthAuthCode::where('code', $request->code)->first();
            $user = $authCode?->user;

            if ($user) {
                $this->activityLogService->logFromRequest(
                    $user,
                    UserActivityLog::ACTION_TOKEN_CREATED,
                    'Acceso concedido a '.$client->name,
                    ['client_id' => $client->id, 'client_name' => $client->name],
                    $request,
                );
            }
        }

        return response()->json($result);
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
