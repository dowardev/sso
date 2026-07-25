<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OAuthAuthCode extends Model
{
    protected $table = 'oauth_auth_codes';

    protected $fillable = [
        'code',
        'user_id',
        'sso_client_id',
        'redirect_uri',
        'code_challenge',
        'code_challenge_method',
        'code_verifier',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->belongsTo(SsoClient::class, 'sso_client_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}