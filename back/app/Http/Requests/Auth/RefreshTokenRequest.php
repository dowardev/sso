<?php

namespace App\Http\Requests\Auth;

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

    public function messages(): array
    {
        return [
            'client_id.exists' => 'The application ":value" is not registered in SSO. Please contact the system administrator.',
        ];
    }
}
