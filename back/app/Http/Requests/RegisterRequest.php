<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests;

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
            'user' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'string', 'min:10', 'confirmed', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/', 'regex:/[@$!%*?&]/'],
            'client_id' => 'required|string|exists:sso_clients,client_id',
            'dpop_public_key' => 'nullable|json',
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.exists' => 'La aplicación ":value" no está registrada en el SSO. Por favor, contacta al administrador del sistema.',
        ];
    }
}
