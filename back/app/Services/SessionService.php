<?php
// SPDX-License-Identifier: AGPL-3.0-only

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

        $deviceFingerprint = hash('sha256', ($request->userAgent() ?? '') . ($request->ip() ?? ''));

        if ($rememberMe) {
            $count = UserSession::where('user_id', $user->id)
                ->where('remember_me', true)
                ->count();

            if ($count >= 5) {
                UserSession::where('user_id', $user->id)
                    ->where('remember_me', true)
                    ->orderBy('last_used_at', 'asc')
                    ->limit($count - 4)
                    ->delete();
            }
        }

        return UserSession::create([
            'user_id' => $user->id,
            'sso_client_id' => $client?->id,
            'token_hash' => hash('sha256', $token),
            'dpop_public_key' => $dpopPublicKey,
            'remember_me' => $rememberMe,
            'device_fingerprint' => $deviceFingerprint,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_used_at' => now(),
        ]);
    }

    public function revokeSessionByToken(string $tokenString): void
    {
        UserSession::where('token_hash', hash('sha256', $tokenString))->delete();
    }

    public function revokeAllUserSessions(User $user): void
    {
        $user->sessions()->with('token')->lazy()->each(function ($session) {
            if ($session->token) {
                $session->token->delete();
            }
        });
        $user->sessions()->delete();
    }

    public function getActiveSessionData(string $tokenString): ?UserSession
    {
        return UserSession::where('token_hash', hash('sha256', $tokenString))->first();
    }
}
