<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Middleware;

use Closure;
use Dowardev\SsoServer\Client\SsoServerClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SsoRoleMiddleware
{
    public function __construct(
        private readonly SsoServerClient $ssoClient,
    ) {}

    public function handle(Request $request, Closure $next, string $role): Response
    {
        if (! $this->ssoClient->isAuthenticated()) {
            return response()->json([
                'message' => 'No autenticado',
                'error' => 'sso_authentication_failed',
            ], 401);
        }

        if (! $this->ssoClient->hasRole($role)) {
            return response()->json([
                'message' => 'Forbidden. '.ucfirst($role).' access required.',
                'error' => 'sso_forbidden',
            ], 403);
        }

        return $next($request);
    }
}
