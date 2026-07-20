<?php

namespace App\Http\Requests;

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

    public function messages(): array
    {
        return [
            'client_id.exists' => 'La aplicación ":value" no está registrada en el SSO. Por favor, contacta al administrador del sistema.',
        ];
    }
}
