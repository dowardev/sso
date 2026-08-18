<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Services;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SettingService
{
    public function all(): Collection
    {
        return Setting::orderBy('key')->get();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Setting::get($key, $default);
    }

    public function update(string $key, string $value): Setting
    {
        $setting = Setting::where('key', $key)->firstOrFail();
        $setting->update(['value' => $value]);
        Setting::clearCache($key);

        return $setting;
    }

    public function set(string $key, mixed $value, string $type, ?string $description = null): Setting
    {
        $setting = Setting::updateOrCreate(
            ['key' => $key],
            [
                'value' => is_array($value) ? json_encode($value) : (string) $value,
                'type' => $type,
                'description' => $description,
            ]
        );
        Setting::clearCache($key);

        return $setting;
    }

    public function uploadLogo(UploadedFile $file): string
    {
        $extension = strtolower($file->guessExtension());
        $path = "branding/auth-logo.{$extension}";

        Storage::disk('public')->putFileAs('branding', $file, "auth-logo.{$extension}");

        $this->set('auth_logo_url', "/storage/{$path}", 'string');

        return "/storage/{$path}";
    }

    public function clearLogo(): void
    {
        collect(Storage::disk('public')->files('branding'))
            ->filter(fn ($path) => str_starts_with(basename($path), 'auth-logo.'))
            ->each(fn ($path) => Storage::disk('public')->delete($path));

        $this->set('auth_logo_url', '', 'string');
    }
}
