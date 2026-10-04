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
                // JwtService::generateToken later in the flow. During the
                // gradual RSA-2048 → ECDSA P-256 migration (LBDS-258) we
                // accept both shapes here; the verifier picks the matching
                // signer from the alg in the proof header.
                function (string $attribute, mixed $value, \Closure $fail) {
                    $jwk = json_decode((string) $value, true);

                    if (! is_array($jwk)) {
                        $fail('The :attribute must be a valid JWK.');

                        return;
                    }

                    $kty = $jwk['kty'] ?? null;
                    if ($kty === 'RSA') {
                        if (! isset($jwk['n'], $jwk['e'])) {
                            $fail('The :attribute must be a valid RSA JWK containing the kty, n and e fields.');
                        }

                        return;
                    }

                    if ($kty === 'EC') {
                        if (! isset($jwk['crv'], $jwk['x'], $jwk['y']) || $jwk['crv'] !== 'P-256') {
                            $fail('The :attribute must be a valid EC P-256 JWK containing the kty, crv, x and y fields.');
                        }

                        return;
                    }

                    $fail('The :attribute must be a valid RSA or EC P-256 JWK.');
                },
            ],
            'code_verifier' => 'required_if:grant_type,authorization_code|string|min:43|max:128',
        ];
    }
}
