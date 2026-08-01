<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserRefreshToken extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'user_agent',
        'ip_address',
        'expires_at',
        'last_used_at',
        'revoked_at',
        'rotated_from_id',
        'remember_me',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'remember_me' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rotatedFrom()
    {
        return $this->belongsTo(self::class, 'rotated_from_id');
    }

    public function rotatedTo()
    {
        return $this->hasOne(self::class, 'rotated_from_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    public function isValid(): bool
    {
        return $this->revoked_at === null && $this->expires_at > now();
    }
}
