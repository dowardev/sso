<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingRequest;
use App\Http\Requests\UploadLogoRequest;
use App\Http\Resources\SettingResource;
use App\Services\SettingService;
use App\Support\ApiError;
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

        return response()->json(ApiError::success(ApiError::SUCCESS_SETTING_UPDATED, 'Settings updated successfully.') + [
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
            return response()->json(
                ApiError::topLevel(ApiError::SETTING_IMAGE_REQUIRED, 'An image is required.'),
                422
            );
        }

        $url = $this->settingService->uploadLogo($file);

        return response()->json(ApiError::success(ApiError::SUCCESS_LOGO_UPDATED, 'Logo updated successfully.') + [
            'auth_logo_url' => $url,
        ]);
    }

    public function destroyLogo(): JsonResponse
    {
        $this->settingService->clearLogo();

        return response()->json(ApiError::success(ApiError::SUCCESS_LOGO_DELETED, 'Logo deleted successfully.') + [
            'auth_logo_url' => null,
        ]);
    }

    public function getEmailLogo(): JsonResponse
    {
        $logo = $this->settingService->get('email_logo_url');
        $logoUrl = is_string($logo) && $logo !== '' ? $logo : null;

        return response()->json(['email_logo_url' => $logoUrl]);
    }

    public function uploadEmailLogo(UploadLogoRequest $request): JsonResponse
    {
        $file = $request->file('image');
        if (! $file instanceof UploadedFile) {
            return response()->json(
                ApiError::topLevel(ApiError::SETTING_IMAGE_REQUIRED, 'An image is required.'),
                422
            );
        }

        $url = $this->settingService->uploadEmailLogo($file);

        return response()->json(ApiError::success(ApiError::SUCCESS_EMAIL_LOGO_UPDATED, 'Email logo updated successfully.') + [
            'email_logo_url' => $url,
        ]);
    }

    public function destroyEmailLogo(): JsonResponse
    {
        $this->settingService->clearEmailLogo();

        return response()->json(ApiError::success(ApiError::SUCCESS_EMAIL_LOGO_DELETED, 'Email logo deleted successfully.') + [
            'email_logo_url' => null,
        ]);
    }
}
