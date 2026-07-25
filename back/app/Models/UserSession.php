<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    protected $fillable = [
        'user_id',
        'sso_client_id',
        'token_id',
        'ip_address',
        'user_agent',
        'last_used_at',
        'token_hash',
        'dpop_public_key',
        'remember_me',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'remember_me' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->belongsTo(SsoClient::class, 'sso_client_id');
    }

    public function token()
    {
        return $this->belongsTo(\Laravel\Sanctum\PersonalAccessToken::class, 'token_id');
    }

    public function scopeActive($query)
    {
        return $query->whereHas('token', function ($q) {
            $q->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
        });
    }
}
