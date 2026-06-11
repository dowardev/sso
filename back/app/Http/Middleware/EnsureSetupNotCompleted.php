<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSetupNotCompleted
{
    public function handle(Request $request, Closure $next): Response
    {
        if (User::exists()) {
            return response()->json([
                'message' => 'Ya existen usuarios registrados en el sistema.',
            ], 403);
        }

        return $next($request);
    }
}