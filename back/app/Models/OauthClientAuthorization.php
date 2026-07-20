<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OauthClientAuthorization extends Model
{
    protected $table = 'oauth_client_authorizations';

    protected $fillable = [
        'user_id',
        'sso_client_id',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->belongsTo(SsoClient::class, 'sso_client_id');
    }
}
