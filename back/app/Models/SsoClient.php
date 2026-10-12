<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SsoClient extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'client_id',
        'client_secret',
        'redirect_url',
        'allowed_origins',
        'grant_types',
        'allowed_scopes',
        'claim_overrides',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'allowed_origins' => 'array',
        'grant_types' => 'array',
        'allowed_scopes' => 'array',
        'claim_overrides' => 'array',
    ];

    protected $hidden = [
        'client_secret',
    ];

    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * Scopes this client is allowed to request. Falls back to the OIDC core
     * + offline_access set advertised in the discovery doc when the column
     * is null, which only happens for rows predating the LBDS-303 migration.
     */
    public function effectiveAllowedScopes(): array
    {
        $scopes = $this->allowed_scopes;

        if (is_array($scopes) && ! empty($scopes)) {
            return $scopes;
        }

        return ['openid', 'profile', 'email', 'offline_access'];
    }

    /**
     * A client is "public" when it has no client_secret — the secret
     * is what makes a client confidential per RFC 6749 §2.1.1, so its
     * absence is the only signal the framework has. Public clients
     * must use PKCE; confidential clients may, but do not have to.
     */
    public function isPublic(): bool
    {
        return ! is_string($this->client_secret) || $this->client_secret === '';
    }

    /**
     * Per-client claim overrides applied on top of the standard OIDC claim
     * set in both the id_token and /oauth/userinfo responses. Shape:
     *
     *   {
     *     "extra_claims": {
     *       "<claim_name>": "<value | user.<field>>",
     *       ...
     *     }
     *   }
     *
     * Values starting with `user.` are resolved from the User model (e.g.
     * `user.email` -> $user->email); anything else is emitted as a literal
     * string. The returned shape is always an array, even when the column
     * is null, so consumers can iterate without null-guards.
     *
     * @return array{extra_claims: array<string, string>}
     */
    public function effectiveClaimOverrides(): array
    {
        $overrides = $this->claim_overrides;

        if (! is_array($overrides)) {
            return ['extra_claims' => []];
        }

        $extra = $overrides['extra_claims'] ?? [];

        if (! is_array($extra)) {
            return ['extra_claims' => []];
        }

        return ['extra_claims' => $extra];
    }
}
