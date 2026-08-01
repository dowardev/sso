<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    private function cspHashList(): string
    {
        $hashes = config('csp.script_hashes', []);

        return empty($hashes) ? '' : ' '.implode(' ', $hashes);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $cspBase = "default-src 'self'; script-src 'self'".$this->cspHashList()."; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https:; connect-src 'self' ".config('app.url').';';

        if (! app()->environment('production')) {
            $response->headers->set('Content-Security-Policy-Report-Only', $cspBase.' report-uri /api/csp-report;');
        } else {
            $response->headers->set('Content-Security-Policy', $cspBase);
        }

        return $response;
    }
}
