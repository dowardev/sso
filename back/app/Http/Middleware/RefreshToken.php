<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RefreshToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $refreshToken = $request->cookie('refresh_token');

        if (! $refreshToken) {
            return response()->json([
                'message' => 'Refresh token no proporcionado',
            ], 401);
        }

        $request->merge(['refresh_token_value' => $refreshToken]);

        return $next($request);
    }
}
