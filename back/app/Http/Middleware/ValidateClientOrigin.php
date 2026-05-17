<?php

namespace App\Http\Middleware;

use App\Models\SsoClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateClientOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');

        if (!$origin) {
            return $next($request);
        }

        $clientId = $request->input('client_id');

        if (!$clientId) {
            $response = response()->json([
                'message' => 'Se requiere client_id para validar el origen',
            ], 403);
            $response->headers->set('Vary', 'Origin');
            return $response;
        }

        $client = SsoClient::where('client_id', $clientId)
            ->where('active', true)
            ->first();

        if (!$client) {
            return response()->json([
                'message' => 'Cliente no encontrado',
            ], 404);
        }

        $allowedOrigins = $client->allowed_origins ?? [];

        if (empty($allowedOrigins)) {
            return $next($request);
        }

        $normalizedOrigin = $this->normalizeOrigin($origin);
        $normalizedAllowedOrigins = array_map(
            [$this, 'normalizeOrigin'],
            $allowedOrigins
        );

        if (!in_array($normalizedOrigin, $normalizedAllowedOrigins, true)) {
            $response = response()->json([
                'message' => 'Origen no autorizado para este cliente',
            ], 403);
            $response->headers->set('Vary', 'Origin');
            return $response;
        }

        return $next($request);
    }

    private function normalizeOrigin(string $origin): string
    {
        $parsed = parse_url($origin);
        $scheme = strtolower($parsed['scheme'] ?? 'https');
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