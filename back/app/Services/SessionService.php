<?php

namespace App\Services;

use App\Models\SsoClient;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;

class SessionService
{
    public function registerSession(User $user, string $token, Request $request, ?string $dpopPublicKey = null, bool $rememberMe = false, ?SsoClient $client = null): UserSession
    {
        if (!$client && $request->client_id) {
            $client = SsoClient::where('client_id', $request->client_id)->first();
        }

        return UserSession::create([
            'user_id' => $user->id,
            'sso_client_id' => $client?->id,
            'plain_token' => $token,
            'dpop_public_key' => $dpopPublicKey,
            'remember_me' => $rememberMe,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_used_at' => now(),
        ]);
    }

    public function revokeSessionByToken(string $tokenString): void
    {
        UserSession::where('plain_token', $tokenString)->delete();
    }

    public function revokeAllUserSessions(User $user): void
    {
        $user->sessions()->with('token')->get()->each(function ($session) {
            if ($session->token) {
                $session->token->delete();
            }
        });
        $user->sessions()->delete();
    }

    public function getActiveSessionData(string $tokenString): ?UserSession
    {
        return UserSession::where('plain_token', $tokenString)->first();
    }
}
