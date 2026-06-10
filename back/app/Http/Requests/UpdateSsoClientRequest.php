<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSsoClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $clientId = $this->route('client');

        return [
            'name' => 'sometimes|string|max:255',
            'client_id' => 'sometimes|string|max:255|unique:sso_clients,client_id,' . $clientId,
            'client_secret' => 'nullable|string|max:255',
            'redirect_url' => 'nullable|string|max:255|url',
            'allowed_origins' => 'nullable|array',
            'allowed_origins.*' => ['regex:/^https?:\/\/[^\/:]+(:\d+)?$/'],
            'grant_types' => 'nullable|array',
            'grant_types.*' => 'string|in:authorization_code,client_credentials',
            'active' => 'sometimes|boolean',
        ];
    }
}
