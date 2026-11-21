<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSetupNotCompleted
{
    public function handle(Request $request, Closure $next): Response
    {
        if (User::exists()) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_FORBIDDEN, 'Ya existen usuarios registrados en el sistema.'),
                403
            );
        }

        return $next($request);
    }
}
