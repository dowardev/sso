<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Types;

class SsoConfig
{
    public function __construct(
        public readonly string $ssoUrl = 'http://localhost:8001/api',
        public readonly string $jwtIssuer = 'http://localhost:8001',
        public readonly int $jwksCacheTtl = 3600,
        public readonly int $rolesCacheTtl = 300,
        public readonly int $dpopReplayTtl = 120,
        public readonly int $dpopIatLeeway = 60,
        public readonly string $clientId = '1',
        public readonly array $roleMappings = [],
    ) {}

    public static function fromConfig(): self
    {
        $clientId = config('sso.client_id');

        if (empty($clientId)) {
            throw new \RuntimeException(
                'sso.client_id is required. Set it in config/sso.php or SSO_CLIENT_ID env.'
            );
        }

        return new self(
            ssoUrl: rtrim(config('sso.sso_url', env('SSO_URL', 'http://localhost:8001/api')), '/'),
            jwtIssuer: config('sso.jwt_issuer', env('SSO_JWT_ISSUER', 'http://localhost:8001')),
            jwksCacheTtl: (int) config('sso.jwks_cache_ttl', 3600),
            rolesCacheTtl: (int) config('sso.roles_cache_ttl', 300),
            dpopReplayTtl: (int) config('sso.dpop_replay_ttl', 120),
            dpopIatLeeway: (int) config('sso.dpop_iat_leeway', 60),
            clientId: $clientId,
            roleMappings: config('sso.role_mappings', []),
        );
    }
}
