<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuthorizeRequest;
use App\Http\Requests\TokenRequest;
use App\Models\Setting;
use App\Services\OAuth2Service;
use Illuminate\Http\JsonResponse;

class OAuth2Controller extends Controller
{
    private OAuth2Service $oauth2Service;

    public function __construct(OAuth2Service $oauth2Service)
    {
        $this->oauth2Service = $oauth2Service;
    }

    public function authorize(AuthorizeRequest $request): JsonResponse
    {
        $client = $this->oauth2Service->validateClientAndGet($request->client_id);

        if (!$client) {
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
        if (!$user) {
            return response()->json([
                'message' => 'No autenticado',
            ], 401);
        }

        $result = $this->oauth2Service->createAuthorizationCode($user, $client, $request->validated());

        return response()->json($result);
    }

    public function token(TokenRequest $request): JsonResponse
    {
        $client = $this->oauth2Service->validateClientAndGet($request->client_id);

        if (!$client) {
            return response()->json([
                'message' => 'Cliente desactivado o no encontrado',
            ], 403);
        }

        if ($request->grant_type === 'client_credentials') {
            $result = $this->oauth2Service->handleClientCredentials($client, $request->validated());

            if (!$result) {
                return response()->json([
                    'message' => 'client_credentials no permitido o credenciales inválidas',
                ], 403);
            }

            return response()->json($result);
        }

        $result = $this->oauth2Service->handleAuthorizationCode($request, $client, $request->validated());

        if (!$result) {
            return response()->json([
                'message' => 'Código de autorización inválido o expirado',
            ], 400);
        }

        return response()->json($result);
    }

    private function isSslRequiredViolation(string $redirectUrl): bool
    {
        if (!Setting::get('require_ssl', false)) {
            return false;
        }

        if (str_starts_with($redirectUrl, 'https://')) {
            return false;
        }

        $host = parse_url($redirectUrl, PHP_URL_HOST);
        if (!$host) {
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
