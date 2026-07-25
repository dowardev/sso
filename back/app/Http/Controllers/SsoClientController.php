<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Http\Requests\StoreSsoClientRequest;
use App\Http\Requests\UpdateSsoClientRequest;
use App\Http\Resources\SsoClientResource;
use App\Models\SsoClient;
use App\Services\SsoClientService;
use Illuminate\Http\JsonResponse;

class SsoClientController extends Controller
{
    private SsoClientService $clientService;

    public function __construct(SsoClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    public function index(): JsonResponse
    {
        $clients = $this->clientService->all();
        return response()->json(SsoClientResource::collection($clients));
    }

    public function show(SsoClient $client): JsonResponse
    {
        return response()->json(new SsoClientResource($client));
    }

    public function store(StoreSsoClientRequest $request): JsonResponse
    {
        $client = $this->clientService->create($request->validated());

        return response()->json([
            'message' => 'Cliente SSO creado correctamente',
            'client' => new SsoClientResource($client),
        ], 201);
    }

    public function update(UpdateSsoClientRequest $request, SsoClient $client): JsonResponse
    {
        $client = $this->clientService->update($client, $request->validated());

        return response()->json([
            'message' => 'Cliente SSO actualizado correctamente',
            'client' => new SsoClientResource($client),
        ]);
    }

    public function destroy(SsoClient $client): JsonResponse
    {
        $this->clientService->delete($client);

        return response()->json([
            'message' => 'Cliente SSO eliminado correctamente',
        ]);
    }
}
