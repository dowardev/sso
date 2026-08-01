<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Http;

use Dowardev\SsoServer\Types\SsoConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SsoHttpClient
{
    private const CLIENT_ID_ROUTES = ['/me', '/check-session'];

    public function __construct(
        private readonly SsoConfig $config,
    ) {}

    public function get(string $path, string $bearerToken): ?array
    {
        return $this->request('get', $path, $bearerToken);
    }

    public function post(string $path, string $bearerToken, array $data = []): ?array
    {
        return $this->request('post', $path, $bearerToken, $data);
    }

    private function request(string $method, string $path, string $bearerToken, array $data = []): ?array
    {
        try {
            $url = $this->config->ssoUrl.'/'.ltrim($path, '/');

            if (in_array($path, self::CLIENT_ID_ROUTES, true)) {
                $separator = str_contains($url, '?') ? '&' : '?';
                $url .= $separator.'client_id='.urlencode($this->config->clientId);
            }

            $request = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$bearerToken,
            ])->timeout(5)->retry(2, 100);

            $response = $method === 'post'
                ? $request->post($url, $data)
                : $request->get($url);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("SSO HTTP request failed: {$method} {$path}", [
                'status' => $response->status(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::warning("SSO HTTP request error: {$method} {$path}: ".$e->getMessage());

            return null;
        }
    }
}
