<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_protected',
    ];

    protected $casts = [
        'is_protected' => 'boolean',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot('sso_client_id')
            ->withTimestamps();
    }

    public function ssoClients(): BelongsToMany
    {
        return $this->belongsToMany(SsoClient::class, 'user_roles')
            ->withPivot('user_id')
            ->withTimestamps();
    }

    public function isProtected(): bool
    {
        return $this->is_protected;
    }
}
