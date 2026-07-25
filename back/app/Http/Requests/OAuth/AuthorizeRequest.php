<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests\OAuth;

use Illuminate\Foundation\Http\FormRequest;

class AuthorizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => 'required|string|exists:sso_clients,client_id',
            'redirect_uri' => 'required|string',
            'state' => 'nullable|string',
            'code_challenge' => 'nullable|string',
            'code_challenge_method' => 'nullable|string|in:S256,plain',
            'code_verifier' => 'nullable|string|min:43|max:128',
        ];
    }
}
