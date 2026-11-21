<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'nullable|string',
            'user' => 'nullable|string',
            'password' => 'required|string',
            'client_id' => 'nullable|string|exists:sso_clients,client_id',
            'dpop_public_key' => 'nullable|json',
            'remember_me' => 'nullable|boolean',
        ];
    }

    // No custom messages: the machine-readable code (`client.not_registered`)
    // carries the user-facing copy (see odd/tasks/api-error-contract-codes.md).
}
