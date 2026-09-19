<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Middleware;

use App\Support\CorsOrigins;
use App\Support\OriginNormalizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleDynamicCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');

        if (! $origin) {
            return $next($request);
        }

        $allowedOrigins = $this->getAllowedOrigins($request);

        if (! OriginNormalizer::matches($origin, $allowedOrigins)) {
            // A rejected preflight is still answered as a preflight: 204 with the
            // diagnostic headers and no Access-Control-Allow-Origin, so the
            // browser still blocks it. Delegating it to $next() would surface a
            // 405 and bury the diagnostic behind an unrelated error.
            $response = $request->isMethod('OPTIONS')
                ? response('', 204)
                : $next($request);

            $this->addVaryOrigin($response);
            $this->addCorsRejectionDiagnostics($response, $origin, $allowedOrigins, $this->getClientIdFromRequest($request));

            return $response;
        }

        if ($request->isMethod('OPTIONS')) {
            return $this->handlePreflight($request, $origin);
        }

        $response = $next($request);

        return $this->addCorsHeaders($response, $origin);
    }

    private function addCorsRejectionDiagnostics(Response $response, string $origin, array $allowedOrigins, ?string $clientId): void
    {
        $response->headers->set('X-CORS-Rejected', 'origin-not-allowed');
        $response->headers->set('X-CORS-Origin', $origin);

        // The allowlist is only published when it belongs to the client named in
        // this request. Without a resolvable client_id it would be the union of
        // every active client's origins, which is not this caller's business.
        if ($clientId) {
            $response->headers->set('X-CORS-Client', $clientId);
            $response->headers->set('X-CORS-Allowed', implode(',', $allowedOrigins));
        }
    }

    private function getAllowedOrigins(Request $request): array
    {
        return CorsOrigins::forClient($this->getClientIdFromRequest($request));
    }

    private function getClientIdFromRequest(Request $request): ?string
    {
        $token = $request->bearerToken();

        if ($token) {
            $parts = explode('.', $token);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode(
                    strtr($parts[1], '-_', '+/')
                ), true);

                if ($payload && isset($payload['client_id'])) {
                    return $payload['client_id'];
                }
            }
        }

        return $request->input('client_id');
    }

    private function handlePreflight(Request $request, string $origin): Response
    {
        $response = response('', 204);

        return $this->addCorsHeaders($response, $origin);
    }

    private function addCorsHeaders(Response $response, string $origin): Response
    {
        $this->addVaryOrigin($response);
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', config('cors.allowed_methods', ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'])));
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', config('cors.allowed_headers', ['Content-Type', 'Authorization', 'X-Requested-With', 'DPoP', 'DPoP-Public-Key', 'Accept'])));
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', (string) config('cors.max_age', 86400));

        $exposedHeaders = config('cors.exposed_headers', []);
        if (! empty($exposedHeaders)) {
            $response->headers->set('Access-Control-Expose-Headers', implode(', ', $exposedHeaders));
        }

        return $response;
    }

    private function addVaryOrigin(Response $response): void
    {
        $existing = $response->headers->get('Vary');
        if ($existing) {
            $parts = array_map('trim', explode(',', $existing));
            if (! in_array('Origin', $parts, true)) {
                $parts[] = 'Origin';
            }
            $response->headers->set('Vary', implode(', ', $parts));
        } else {
            $response->headers->set('Vary', 'Origin');
        }
    }
}
