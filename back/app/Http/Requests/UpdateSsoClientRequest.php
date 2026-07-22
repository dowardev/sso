<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSsoClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $client = $this->route('client');
        $clientId = $client instanceof \App\Models\SsoClient ? $client->id : $client;

        return [
            'name' => 'sometimes|string|max:255',
            'client_id' => 'sometimes|string|max:255|unique:sso_clients,client_id,' . $clientId,
            'client_secret' => 'nullable|string|max:255',
            'redirect_url' => 'nullable|string|max:255|url',
            'allowed_origins' => 'nullable|array',
            'allowed_origins.*' => ['regex:/^https?:\/\/[^\/:]+(:\d+)?$/'],
            'grant_types' => 'nullable|array',
            'grant_types.*' => 'string|in:authorization_code,client_credentials',
            'active' => 'sometimes|boolean',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $redirectUrl = $this->input('redirect_url');
            if ($redirectUrl && !$this->isRedirectUrlAllowed($redirectUrl)) {
                $validator->errors()->add(
                    'redirect_url',
                    'El redirect_url debe usar HTTPS cuando la opción de requerir SSL está activa'
                );
            }
        });
    }

    private function isRedirectUrlAllowed(string $url): bool
    {
        if (str_starts_with($url, 'https://')) {
            return true;
        }

        if (!str_starts_with($url, 'http://')) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return true;
        }

        $defaultExclusions = ['localhost', '127.0.0.1', '::1'];
        if (in_array($host, $defaultExclusions)) {
            return true;
        }

        $customExclusions = \App\Models\Setting::get('ssl_exclusions', []);
        if (is_array($customExclusions) && in_array($host, $customExclusions)) {
            return true;
        }

        if (\App\Models\Setting::get('require_ssl', false) === false) {
            return true;
        }

        return false;
    }
}
