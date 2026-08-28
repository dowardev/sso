<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'user',
        'email',
        'email_verified_at',
        'password',
        'login_id',
        'encryption_level',
        'profile_version',
        'salt_a',
        'salt_r',
        'wrapped_dek_a',
        'wrapped_dek_r',
        'encrypted_profile',
        'encrypted_seed_verification',
        'passkey_public_key',
        'passkey_credential_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'login_id',
        'encryption_level',
        'profile_version',
        'salt_a',
        'salt_r',
        'wrapped_dek_a',
        'wrapped_dek_r',
        'encrypted_profile',
        'encrypted_seed_verification',
        'passkey_public_key',
        'passkey_credential_id',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'encryption_level' => 'string',
        ];
    }

    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(UserActivityLog::class);
    }

        public function passkeyCredentials(): HasMany
        {
            return $this->hasMany(PasskeyCredential::class);
        }

    public function activeTokens()
    {
        return $this->tokens()->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    public function oauthClientAuthorizations(): HasMany
    {
        return $this->hasMany(OauthClientAuthorization::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withTimestamps();
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles()->where('slug', $slug)->exists();
    }

    protected function isAdmin(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->relationLoaded('roles')
                ? $this->roles->contains(fn (Role $role) => $role->slug === 'admin')
                : $this->hasRole('admin'),
        );
    }

    protected function isAdvanced(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->encryption_level === 'advanced',
        );
    }

    protected $appends = [
        'is_admin',
        'is_advanced',
    ];
}