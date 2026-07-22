<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SsoClient extends Model
{
    protected $fillable = [
        'name',
        'client_id',
        'client_secret',
        'redirect_url',
        'allowed_origins',
        'grant_types',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'allowed_origins' => 'array',
        'grant_types' => 'array',
    ];

    protected $hidden = [
        'client_secret',
    ];

    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }
}
