<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TotpCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'encrypted_secret',
        'confirmed_at',
        'last_used_step',
        'digits',
        'period',
        'algorithm',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'last_used_step' => 'integer',
        'digits' => 'integer',
        'period' => 'integer',
    ];

    /**
     * The credential is "active" (i.e. the user has successfully verified
     * their first code) only after `confirmed_at` is set. Pending
     * enrollments — secret generated but never confirmed — are kept around
     * so the confirm step can validate without re-issuing a new secret, but
     * they MUST NOT participate in the login challenge.
     */
    public function isActive(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function backupCodes(): HasMany
    {
        return $this->hasMany(TotpBackupCode::class, 'user_id', 'user_id');
    }
}
