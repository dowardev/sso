<?php

namespace TuOrg\SsoServer\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TuOrg\SsoServer\Client\SsoServerClient;

class SsoRoleMiddleware
{
    public function __construct(
        private readonly SsoServerClient $ssoClient,
    ) {}

    public function handle(Request $request, Closure $next, string $role): Response
    {
        if (!$this->ssoClient->isAuthenticated()) {
            return response()->json([
                'message' => 'No autenticado',
                'error' => 'sso_authentication_failed',
            ], 401);
        }

        if (!$this->ssoClient->hasRole($role)) {
            return response()->json([
                'message' => 'Forbidden. ' . ucfirst($role) . ' access required.',
                'error' => 'sso_forbidden',
            ], 403);
        }

        return $next($request);
    }
}
