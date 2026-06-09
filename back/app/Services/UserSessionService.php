<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class UserSessionService
{
    public function paginate(Request $request): LengthAwarePaginator
    {
        $query = UserSession::with(['user:id,name,user,email', 'client:id,name,client_id', 'token']);

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('client_id')) {
            $query->where('sso_client_id', $request->client_id);
        }

        if ($request->has('active') && $request->active) {
            $query->active();
        }

        $sessions = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        $sessions->getCollection()->transform(function ($session) {
            $session->token_active = $session->token &&
                ($session->token->expires_at === null || $session->token->expires_at->isFuture());
            return $session;
        });

        return $sessions;
    }

    public function getUserSessions(User $user): Collection
    {
        $sessions = $user->sessions()
            ->with(['client:id,name,client_id', 'token'])
            ->orderBy('created_at', 'desc')
            ->get();

        $sessions->transform(function ($session) {
            $session->token_active = $session->token &&
                ($session->token->expires_at === null || $session->token->expires_at->isFuture());
            return $session;
        });

        return $sessions;
    }

    public function destroy(UserSession $session): void
    {
        if ($session->token) {
            $session->token->delete();
        }
        $session->delete();
    }

    public function destroyAllForUser(User $user): void
    {
        $user->sessions()->with('token')->get()->each(function ($session) {
            if ($session->token) {
                $session->token->delete();
            }
        });
        $user->sessions()->delete();
    }
}
