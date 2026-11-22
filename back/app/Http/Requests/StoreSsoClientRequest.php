<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests;

use App\Models\Setting;
use App\Rules\RedirectUri;
use Illuminate\Foundation\Http\FormRequest;

class StoreSsoClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'client_id' => 'required|string|max:255|unique:sso_clients',
            'client_secret' => 'nullable|string|max:255',
            'redirect_url' => ['nullable', 'string', 'max:255', new RedirectUri],
            'allowed_origins' => 'nullable|array',
            'allowed_origins.*' => ['regex:/^https?:\/\/[^\/:]+(:\d+)?$/'],
            'grant_types' => 'nullable|array',
            'grant_types.*' => 'string|in:authorization_code,client_credentials',
            'active' => 'sometimes|boolean',
        ];

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->requiresRedirectUrl() && ! $this->input('redirect_url')) {
                $validator->errors()->add(
                    'redirect_url',
                    'El redirect_url es obligatorio para clientes con authorization_code: el SSO lo usa para validar el callback.'
                );
            }

            $redirectUrl = $this->input('redirect_url');
            if ($redirectUrl && ! $this->isRedirectUrlAllowed($redirectUrl)) {
                $validator->errors()->add(
                    'redirect_url',
                    'The redirect_url must use HTTPS when the require-SSL option is enabled'
                );
            }
        });
    }

    private function requiresRedirectUrl(): bool
    {
        $grantTypes = $this->input('grant_types', []);

        return is_array($grantTypes) && in_array('authorization_code', $grantTypes, true);
    }

    private function isRedirectUrlAllowed(string $url): bool
    {
        if (str_starts_with($url, 'https://')) {
            return true;
        }

        if (! str_starts_with($url, 'http://')) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return true;
        }

        $defaultExclusions = ['localhost', '127.0.0.1', '::1'];
        if (in_array($host, $defaultExclusions)) {
            return true;
        }

        $customExclusions = Setting::get('ssl_exclusions', []);
        if (is_array($customExclusions) && in_array($host, $customExclusions)) {
            return true;
        }

        if (Setting::get('require_ssl', false) === false) {
            return true;
        }

        return false;
    }
}
