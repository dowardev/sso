<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user');

        return [
            'name' => 'sometimes|string|max:255',
            'user' => [
                'sometimes',
                'string',
                'min:3',
                'max:255',
                'unique:users,user,'.$userId,
                'regex:/^[a-zA-Z0-9_-]+$/',
            ],
            'email' => 'sometimes|string|email|max:255|unique:users,email,'.$userId,
            'password' => [
                'sometimes',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z0-9]).+$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user.regex' => 'The user may only contain letters, numbers, underscores, and hyphens.',
            'password.regex' => 'The password must be at least 8 characters and include at least one uppercase letter, one number, and one special character.',
        ];
    }
}
