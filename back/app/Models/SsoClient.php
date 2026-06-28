<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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

    protected static function booted(): void
    {
        static::saved(function ($client) {
            self::invalidateCorsCache($client);
        });

        static::deleted(function ($client) {
            self::invalidateCorsCache($client);
        });
    }

    private static function invalidateCorsCache($client): void
    {
        Cache::forget('sso_cors_origins_' . $client->client_id);
        Cache::forget('sso_cors_origins___sso__');
    }

    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }
}
