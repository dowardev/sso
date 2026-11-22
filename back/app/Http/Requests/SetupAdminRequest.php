<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SetupAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'user' => 'required|string|max:255|unique:users,user',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'The name is required.',
            'user.required' => 'The username is required.',
            'user.unique' => 'The username is already in use.',
            'email.required' => 'The email address is required.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'The email address is already registered.',
            'password.required' => 'The password is required.',
            'password.min' => 'The password must be at least 12 characters long.',
            'password.confirmed' => 'The passwords do not match.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $password = $this->input('password') ?? '';

            if (! preg_match('/[A-Z]/', $password)) {
                $validator->errors()->add('password', 'It must contain at least one uppercase letter.');
            }
            if (! preg_match('/[a-z]/', $password)) {
                $validator->errors()->add('password', 'It must contain at least one lowercase letter.');
            }
            if (! preg_match('/[0-9]/', $password)) {
                $validator->errors()->add('password', 'It must contain at least one number.');
            }
            if (! preg_match('/[@$!%*?&]/', $password)) {
                $validator->errors()->add('password', 'It must contain at least one special character (@ $ ! % * ? &).');
            }
        });
    }
}
