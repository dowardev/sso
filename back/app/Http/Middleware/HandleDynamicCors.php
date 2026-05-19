<?php

namespace App\Http\Middleware;

use App\Models\SsoClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class HandleDynamicCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');

        if (!$origin) {
            return $next($request);
        }

        $allowedOrigins = $this->getAllowedOrigins();
        $normalizedOrigin = $this->normalizeOrigin($origin);
        $normalizedAllowedOrigins = array_map(
            [$this, 'normalizeOrigin'],
            $allowedOrigins
        );

        if (!in_array($normalizedOrigin, $normalizedAllowedOrigins, true)) {
            $response = $next($request);
            $this->addVaryOrigin($response);
            return $response;
        }

        if ($request->isMethod('OPTIONS')) {
            return $this->handlePreflight($request, $origin);
        }

        $response = $next($request);

        return $this->addCorsHeaders($response, $origin);
    }

    private function getAllowedOrigins(): array
    {
        $origins = Cache::remember('sso_cors_origins', 60, function () {
            $clients = SsoClient::where('active', true)
                ->whereNotNull('allowed_origins')
                ->get(['allowed_origins']);

            $origins = [];
            foreach ($clients as $client) {
                $origins = array_merge($origins, $client->allowed_origins ?? []);
            }

            return array_unique($origins);
        });

        $ssoOrigin = config('app.frontend_url') ?? config('app.url');
        if ($ssoOrigin) {
            $origins[] = $ssoOrigin;
        }

        return $origins;
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
        if (!empty($exposedHeaders)) {
            $response->headers->set('Access-Control-Expose-Headers', implode(', ', $exposedHeaders));
        }

        return $response;
    }

    private function addVaryOrigin(Response $response): void
    {
        $existing = $response->headers->get('Vary');
        if ($existing) {
            $parts = array_map('trim', explode(',', $existing));
            if (!in_array('Origin', $parts, true)) {
                $parts[] = 'Origin';
            }
            $response->headers->set('Vary', implode(', ', $parts));
        } else {
            $response->headers->set('Vary', 'Origin');
        }
    }

    private function normalizeOrigin(string $origin): string
    {
        $parsed = parse_url($origin);
        $scheme = strtolower($parsed['scheme'] ?? 'http');
        $host = strtolower($parsed['host'] ?? '');
        $port = $parsed['port'] ?? null;

        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        $normalized = "{$scheme}://{$host}";
        if ($port) {
            $normalized .= ":{$port}";
        }

        return $normalized;
    }
}