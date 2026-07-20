<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingRequest;
use App\Http\Resources\SettingResource;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;

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
}
