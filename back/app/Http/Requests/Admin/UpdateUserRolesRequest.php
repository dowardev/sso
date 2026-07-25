<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roles' => 'required|array',
            'roles.*.role_id' => 'required|exists:roles,id',
            'roles.*.sso_client_id' => 'nullable|exists:sso_clients,id',
        ];
    }
}
