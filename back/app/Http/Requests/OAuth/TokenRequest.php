<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests\OAuth;

use Illuminate\Foundation\Http\FormRequest;

class TokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grant_type' => 'required|string|in:authorization_code,client_credentials',
            'client_id' => 'required|string|exists:sso_clients,client_id',
            'client_secret' => 'nullable|string',
            'code' => 'required_if:grant_type,authorization_code|string',
            'redirect_uri' => 'required_if:grant_type,authorization_code|string',
            'dpop_public_key' => 'nullable|json',
            'code_verifier' => 'nullable|string|min:43|max:128',
        ];
    }
}
