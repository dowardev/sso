<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserActivityLog extends Model
{
    const ACTION_LOGIN = 'login';

    const ACTION_LOGOUT = 'logout';

    const ACTION_REGISTER = 'register';

    const ACTION_PROFILE_UPDATE = 'profile_update';

    const ACTION_PASSWORD_CHANGE = 'password_change';

    const ACTION_SESSION_REVOKED = 'session_revoked';

    const ACTION_AUTHORIZATION_GRANTED = 'authorization_granted';

    const ACTION_AUTHORIZATION_REVOKED = 'authorization_revoked';

    const ACTION_AUTHORIZATION_DENIED = 'authorization_denied';

    const ACTION_TOKEN_CREATED = 'token_created';

    const ACTION_TOKEN_REFRESHED = 'token_refreshed';

    const ACTION_ACCOUNT_DELETED = 'account_deleted';

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'description',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
