<?php

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
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'allowed_origins' => 'array',
    ];

    protected $hidden = [
        'client_secret',
    ];

    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }
}
