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

        // Usar hasRole en lugar de is_admin
        $ssoClientId = $request->query('sso_client_id');

        if ($ssoClientId) {
            // Verificar rol específico del cliente
            $effectiveRole = $user->getEffectiveRole((int) $ssoClientId);
            if (!$effectiveRole || $effectiveRole->slug !== 'admin') {
                return response()->json(['error' => 'Forbidden - admin role required for this client'], 403);
            }
        } else {
            // Verificar rol global
            if (!$user->hasRole('admin')) {
                return response()->json(['error' => 'Forbidden - admin role required'], 403);
            }
        }

        return $next($request);
    }
}