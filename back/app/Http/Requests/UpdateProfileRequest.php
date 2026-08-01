<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user') ?? $this->user()->id;

        return [
            'name' => 'required|string|max:255',
            'user' => 'required|string|max:255|unique:users,user,'.$userId,
            'email' => 'required|string|email|max:255|unique:users,email,'.$userId,
        ];
    }
}
