<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefreshTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => 'required|string',
            'client_id' => 'nullable|string|exists:sso_clients,client_id',
        ];
    }
}
