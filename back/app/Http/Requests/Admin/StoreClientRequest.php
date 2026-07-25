<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'client_id' => 'required|string|max:255|unique:sso_clients',
            'client_secret' => 'nullable|string|max:255',
            'redirect_url' => 'nullable|string|max:255|url',
            'allowed_origins' => 'nullable|array',
            'allowed_origins.*' => ['regex:/^https?:\/\/[^\/:]+(:\d+)?$/'],
            'grant_types' => 'nullable|array',
            'grant_types.*' => 'string|in:authorization_code,client_credentials',
            'active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'allowed_origins.*.regex' => 'Each allowed origin must be a valid URL (http:// or https://) without path.',
        ];
    }
}
