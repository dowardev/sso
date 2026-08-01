<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'user' => [
                'required',
                'string',
                'min:3',
                'max:255',
                'unique:users',
                'regex:/^[a-zA-Z0-9_-]+$/',
            ],
            'email' => 'required|string|email|max:255|unique:users',
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z0-9]).+$/',
            ],
            'client_id' => 'required|string|exists:sso_clients,client_id',
            'dpop_public_key' => 'nullable|json',
        ];
    }

    public function messages(): array
    {
        return [
            'user.regex' => 'The user may only contain letters, numbers, underscores, and hyphens.',
            'password.regex' => 'The password must be at least 8 characters and include at least one uppercase letter, one number, and one special character.',
            'client_id.exists' => 'The application ":value" is not registered in SSO. Please contact the system administrator.',
        ];
    }
}
