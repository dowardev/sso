<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClientCredentialsAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $grantType = $request->get('sso_grant_type');

        if ($grantType !== 'client_credentials') {
            return response()->json([
                'message' => 'Se requiere token de client_credentials'
            ], 403);
        }

        return $next($request);
    }
}
