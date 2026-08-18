<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingRequest;
use App\Http\Requests\UploadLogoRequest;
use App\Http\Resources\SettingResource;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

class SettingController extends Controller
{
    private SettingService $settingService;

    public function __construct(SettingService $settingService)
    {
        $this->settingService = $settingService;
    }

    public function index(): JsonResponse
    {
        $settings = $this->settingService->all();

        return response()->json(SettingResource::collection($settings));
    }

    public function update(UpdateSettingRequest $request, string $key): JsonResponse
    {
        $setting = $this->settingService->update($key, $request->validated()['value']);

        return response()->json([
            'message' => 'Configuración actualizada correctamente',
            'setting' => new SettingResource($setting),
        ]);
    }

    public function publicIndex(): JsonResponse
    {
        $logo = $this->settingService->get('auth_logo_url');
        $logoUrl = is_string($logo) && $logo !== '' ? $logo : null;

        return response()->json(['auth_logo_url' => $logoUrl]);
    }

    public function uploadLogo(UploadLogoRequest $request): JsonResponse
    {
        $file = $request->file('image');
        if (! $file instanceof UploadedFile) {
            return response()->json(['message' => 'Imagen requerida'], 422);
        }

        $url = $this->settingService->uploadLogo($file);

        return response()->json([
            'message' => 'Logo actualizado correctamente',
            'auth_logo_url' => $url,
        ]);
    }

    public function destroyLogo(): JsonResponse
    {
        $this->settingService->clearLogo();

        return response()->json([
            'message' => 'Logo eliminado correctamente',
            'auth_logo_url' => null,
        ]);
    }
}
