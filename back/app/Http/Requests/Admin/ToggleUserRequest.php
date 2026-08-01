<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ToggleUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => 'required|in:assign,remove',
            'sso_client_id' => 'nullable|integer',
        ];
    }
}
