<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $ssoUserId = $request->get('sso_user_id');

        if (! $ssoUserId) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_UNAUTHENTICATED, 'Unauthorized'),
                401
            );
        }

        $user = User::find($ssoUserId);

        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_USER_NOT_FOUND, 'User not found'),
                404
            );
        }

        if (! $user->hasRole('admin')) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_FORBIDDEN, 'Forbidden - admin role required'),
                403
            );
        }

        return $next($request);
    }
}
