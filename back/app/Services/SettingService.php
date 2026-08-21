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
        $this->purgeBranding('auth-logo.');

        $this->set('auth_logo_url', '', 'string');
    }

    public function uploadEmailLogo(UploadedFile $file): string
    {
        $extension = strtolower($file->guessExtension());
        $path = "branding/email-logo.{$extension}";

        Storage::disk('public')->putFileAs('branding', $file, "email-logo.{$extension}");

        $this->set('email_logo_url', "/storage/{$path}", 'string');

        return "/storage/{$path}";
    }

    public function clearEmailLogo(): void
    {
        $this->purgeBranding('email-logo.');

        $this->set('email_logo_url', '', 'string');
    }

    private function purgeBranding(string $prefix): void
    {
        collect(Storage::disk('public')->files('branding'))
            ->filter(fn ($path) => str_starts_with(basename($path), $prefix))
            ->each(fn ($path) => Storage::disk('public')->delete($path));
    }
}
