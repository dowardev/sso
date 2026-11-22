<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Http\Resources\UserSessionResource;
use App\Models\User;
use App\Models\UserSession;
use App\Services\UserSessionService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSessionController extends Controller
{
    private UserSessionService $sessionService;

    public function __construct(UserSessionService $sessionService)
    {
        $this->sessionService = $sessionService;
    }

    public function index(Request $request): JsonResponse
    {
        $sessions = $this->sessionService->paginate($request);

        return response()->json($sessions);
    }

    public function userSessions(User $user): JsonResponse
    {
        $sessions = $this->sessionService->getUserSessions($user);

        return response()->json([
            'user' => $user->only(['id', 'name', 'user', 'email']),
            'sessions' => UserSessionResource::collection($sessions),
            'total' => $sessions->count(),
            'active' => $sessions->where('token_active', true)->count(),
        ]);
    }

    public function destroy(UserSession $session): JsonResponse
    {
        $this->sessionService->destroy($session);

        return response()->json(ApiError::success(ApiError::SUCCESS_SESSION_REVOKED, 'Session revoked successfully.'));
    }

    public function destroyUserSessions(User $user, Request $request): JsonResponse
    {
        $authUser = $request->user();

        if ($user->id === $authUser->id) {
            return response()->json(
                ApiError::topLevel(ApiError::SESSION_SELF_REVOKE_FORBIDDEN, 'You cannot revoke your own sessions from the admin panel.'),
                422
            );
        }

        $this->sessionService->destroyAllForUser($user);

        return response()->json(
            ApiError::success(ApiError::SUCCESS_USER_SESSIONS_REVOKED, "All the user's sessions have been revoked.")
        );
    }
}
