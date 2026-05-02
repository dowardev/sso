<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->get('sso_user_id');

        if (!$userId) {
            return response()->json([
                'message' => 'No autenticado',
            ], 401);
        }

        $user = User::find($userId);

        if (!$user || !$user->is_admin) {
            return response()->json([
                'message' => 'No tienes permisos de administrador',
            ], 403);
        }

        return $next($request);
    }
}
