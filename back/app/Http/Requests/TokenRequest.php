<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grant_type' => 'required|string|in:authorization_code,client_credentials,refresh_token',
            'client_id' => 'required|string|exists:sso_clients,client_id',
            'client_secret' => 'nullable|string',
            'code' => 'required_if:grant_type,authorization_code|string',
            'redirect_uri' => 'required_if:grant_type,authorization_code|string',
            'refresh_token' => 'nullable|string',
            'refreshToken' => 'nullable|string',
            'dpop_public_key' => [
                'nullable',
                'json',
                // Present-but-unusable keys must be a 422 at the endpoint,
                // not an uncaught InvalidArgumentException (500) inside
                // JwtService::generateToken later in the flow.
                function (string $attribute, mixed $value, \Closure $fail) {
                    $jwk = json_decode((string) $value, true);

                    if (! is_array($jwk)
                        || ($jwk['kty'] ?? null) !== 'RSA'
                        || ! isset($jwk['n'], $jwk['e'])
                    ) {
                        $fail('The :attribute must be a valid RSA JWK containing the kty, n and e fields.');
                    }
                },
            ],
            'code_verifier' => 'required_if:grant_type,authorization_code|string|min:43|max:128',
        ];
    }
}
