<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetToken extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending($query)
    {
        return $query->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function markAsUsed(): void
    {
        $this->update(['used_at' => now()]);
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    public function isValid(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at > now();
    }
}
