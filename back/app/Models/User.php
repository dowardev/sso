<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'user',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }

    public function activeTokens()
    {
        return $this->tokens()->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('sso_client_id')
            ->withTimestamps();
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles()->where('slug', $slug)->exists();
    }

    public function getEffectiveRole($ssoClientId = null)
    {
        $specificRole = $this->roles()
            ->where('sso_client_id', $ssoClientId)
            ->first();

        if ($specificRole) {
            return $specificRole;
        }

        $globalRole = $this->roles()
            ->whereNull('sso_client_id')
            ->first();

        if ($globalRole) {
            return $globalRole;
        }

        return Role::where('slug', 'viewer')->first();
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }
}
