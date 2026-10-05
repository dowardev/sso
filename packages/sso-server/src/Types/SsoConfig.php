<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Types;

class SsoConfig
{
    /**
     * Algorithms accepted by the DPoP proof verifier by default during the
     * gradual RSA-2048 → ECDSA P-256 migration tracked by LBDS-290.
     * Override via config('sso.dpop_allowed_algs') or
     * `SSO_DPOP_ALLOWED_ALGS=ES256` (comma-separated) when cutting over.
     */
    public const DEFAULT_DPOP_ALLOWED_ALGS = ['ES256', 'RS256'];

    /**
     * @param  array<int, string>  $dpopAllowedAlgs
     * @param  array<string, mixed>  $roleMappings
     */
    public function __construct(
        public readonly string $ssoUrl = 'http://localhost:8001/api',
        public readonly string $jwtIssuer = 'http://localhost:8001',
        public readonly int $jwksCacheTtl = 3600,
        public readonly int $rolesCacheTtl = 300,
        public readonly int $dpopReplayTtl = 120,
        public readonly int $dpopIatLeeway = 60,
        public readonly string $clientId = '1',
        public readonly array $roleMappings = [],
        public readonly array $dpopAllowedAlgs = self::DEFAULT_DPOP_ALLOWED_ALGS,
    ) {}

    public static function fromConfig(): self
    {
        $clientId = config('sso.client_id');

        if (empty($clientId)) {
            throw new \RuntimeException(
                'sso.client_id is required. Set it in config/sso.php or SSO_CLIENT_ID env.'
            );
        }

        $configured = config('sso.dpop_allowed_algs');
        $dpopAllowedAlgs = is_array($configured) && $configured !== []
            ? array_values(array_unique(array_map('strval', $configured)))
            : self::DEFAULT_DPOP_ALLOWED_ALGS;

        return new self(
            ssoUrl: rtrim(config('sso.sso_url', env('SSO_URL', 'http://localhost:8001/api')), '/'),
            jwtIssuer: config('sso.jwt_issuer', env('SSO_JWT_ISSUER', 'http://localhost:8001')),
            jwksCacheTtl: (int) config('sso.jwks_cache_ttl', 3600),
            rolesCacheTtl: (int) config('sso.roles_cache_ttl', 300),
            dpopReplayTtl: (int) config('sso.dpop_replay_ttl', 120),
            dpopIatLeeway: (int) config('sso.dpop_iat_leeway', 60),
            clientId: $clientId,
            roleMappings: config('sso.role_mappings', []),
            dpopAllowedAlgs: $dpopAllowedAlgs,
        );
    }
}
