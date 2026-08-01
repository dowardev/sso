<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Middleware;

use Closure;
use Dowardev\SsoServer\Client\SsoServerClient;
use Dowardev\SsoServer\Exceptions\DpopVerificationException;
use Dowardev\SsoServer\Exceptions\JwksFetchException;
use Dowardev\SsoServer\Exceptions\SsoAuthenticationException;
use Dowardev\SsoServer\Exceptions\SsoUserResolveException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SsoAuthMiddleware
{
    public function __construct(
        private readonly SsoServerClient $ssoClient,
    ) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return response()->json([
                'message' => 'Token no proporcionado',
                'error' => 'sso_authentication_failed',
            ], 401);
        }

        $dpopProof = $request->header('DPoP');
        $requireDpop = $mode === 'dpop';

        try {
            $uri = $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo();

            $this->ssoClient->authenticateRequest(
                $bearerToken,
                $dpopProof,
                $request->method(),
                $uri,
                $requireDpop,
            );

            $request->merge([
                'sso_user_id' => $this->ssoClient->getId(),
            ]);
        } catch (SsoAuthenticationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->getErrorCode(),
            ], $e->getStatusCode());
        } catch (DpopVerificationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->getErrorCode(),
            ], $e->getStatusCode());
        } catch (JwksFetchException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->getErrorCode(),
            ], $e->getStatusCode());
        } catch (SsoUserResolveException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'sso_resolve_failed',
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Token inválido: '.$e->getMessage(),
                'error' => 'sso_authentication_failed',
            ], 401);
        }

        return $next($request);
    }
}
