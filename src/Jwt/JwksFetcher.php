<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Jwt;

use Dowardev\SsoServer\Exceptions\JwksFetchException;
use Dowardev\SsoServer\Types\SsoConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JwksFetcher
{
    public function __construct(
        private readonly SsoConfig $config,
        private readonly JwkToPemConverter $converter,
    ) {}

    public function getPublicKeyPem(): string
    {
        return Cache::remember(
            'sso_jwt_public_key',
            $this->config->jwksCacheTtl,
            fn () => $this->fetchPublicKeyFromJwks()
        );
    }

    public function getJwks(): ?array
    {
        return Cache::remember(
            'sso_jwks',
            $this->config->jwksCacheTtl,
            fn () => $this->fetchJwks()
        );
    }

    public function clearCache(): void
    {
        Cache::forget('sso_jwt_public_key');
        Cache::forget('sso_jwks');
    }

    private function fetchPublicKeyFromJwks(): string
    {
        $jwks = $this->fetchJwks();

        if (! $jwks || empty($jwks['keys'])) {
            throw JwksFetchException::emptyJwks();
        }

        $keyData = $jwks['keys'][0];

        return $this->converter->convert($keyData);
    }

    private function fetchJwks(): ?array
    {
        try {
            $url = $this->config->ssoUrl.'/.well-known/jwks';

            $response = Http::timeout(5)->get($url);

            if ($response->successful()) {
                return $response->json();
            }

            throw JwksFetchException::unreachable($this->config->ssoUrl);
        } catch (JwksFetchException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to connect to SSO JWKS endpoint: '.$e->getMessage());
            throw JwksFetchException::unreachable($this->config->ssoUrl);
        }
    }
}
