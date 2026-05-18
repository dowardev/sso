<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $ssoUserId = $request->get('sso_user_id');

        if (!$ssoUserId) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = \App\Models\User::find($ssoUserId);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        if (!$user->hasRole('admin')) {
            return response()->json(['error' => 'Forbidden - admin role required'], 403);
        }

        return $next($request);
    }
}