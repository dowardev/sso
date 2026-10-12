<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AuthorizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => 'required|string|exists:sso_clients,client_id',
            'redirect_uri' => 'required|string',
            'state' => 'nullable|string',
            'scope' => 'nullable|string',
            'nonce' => 'nullable|string|max:255',
            // PKCE is required for *public* clients (no client_secret
            // in the SsoClient row) and optional for *confidential*
            // clients. The controller enforces the public-client rule
            // after looking up the client; making the field conditional
            // here would require loading the client inside a FormRequest,
            // which the framework discourages. The trade-off keeps the
            // validation layer dumb and pushes the conditional to the
            // domain check that has the client context.
            //
            // RFC 7636 §1 marks PKCE as RECOMMENDED for confidential
            // clients; making it required for them was the LBDS-303
            // original scope but the pre-merge review surfaced that
            // the RFC deviation is a bigger breaking change than the
            // ticket's defense-in-depth argument needs. Confidential
            // clients with a leaked secret are still vulnerable to
            // impersonation, but that is the same risk every other
            // IdP accepts, and the per-client flag gives the SSO a
            // path back to "all clients" if the operator wants it.
            'code_challenge' => 'nullable|string',
            'code_challenge_method' => 'nullable|string|in:S256,plain',
        ];
    }
}
