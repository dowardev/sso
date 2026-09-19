<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests;

use App\Models\Setting;
use App\Models\SsoClient;
use App\Rules\RedirectUri;
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
        $clientId = $client instanceof SsoClient ? $client->id : $client;

        return [
            'name' => 'sometimes|string|max:255',
            'client_id' => 'sometimes|string|max:255|unique:sso_clients,client_id,'.$clientId,
            'client_secret' => 'nullable|string|max:255',
            'redirect_url' => ['nullable', 'string', 'max:255', new RedirectUri],
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
            if ($this->requiresRedirectUrl() && ! $this->effectiveRedirectUrl()) {
                $validator->errors()->add(
                    'redirect_url',
                    'El redirect_url es obligatorio para clientes con authorization_code: el SSO lo usa para validar el callback.'
                );
            }

            $redirectUrl = $this->input('redirect_url');
            if ($redirectUrl && ! $this->isRedirectUrlAllowed($redirectUrl)) {
                $validator->errors()->add(
                    'redirect_url',
                    'El redirect_url debe usar HTTPS cuando la opción de requerir SSL está activa'
                );
            }
        });
    }

    private function requiresRedirectUrl(): bool
    {
        $grantTypes = $this->input('grant_types');

        if (! is_array($grantTypes)) {
            $client = $this->route('client');
            $grantTypes = $client instanceof SsoClient ? ($client->grant_types ?? []) : [];
        }

        return in_array('authorization_code', $grantTypes, true);
    }

    /**
     * The redirect_url the client would end up with: the submitted value when the
     * request carries the key (even as null, which clears it), the stored one
     * otherwise.
     */
    private function effectiveRedirectUrl(): ?string
    {
        if ($this->has('redirect_url')) {
            $value = $this->input('redirect_url');

            return is_string($value) && $value !== '' ? $value : null;
        }

        $client = $this->route('client');

        return $client instanceof SsoClient ? $client->redirect_url : null;
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
