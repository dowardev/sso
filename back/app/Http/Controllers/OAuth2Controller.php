<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuthorizeRequest;
use App\Http\Requests\TokenRequest;
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
}
