<?php

return [
    'sso_url' => env('SSO_URL', 'http://localhost:8001/api'),

    'jwt_issuer' => env('SSO_JWT_ISSUER', 'http://localhost:8001'),

    'jwks_cache_ttl' => (int) env('JWKS_CACHE_TTL', 3600),

    'roles_cache_ttl' => 300,

    'dpop_replay_ttl' => 120,

    'dpop_iat_leeway' => 60,

    'client_id' => env('SSO_CLIENT_ID', '1'),

    'role_mappings' => [
        'admin' => ['slug' => 'admin'],
    ],

    // Algorithms accepted by the DPoP proof verifier. The default is the
    // post-LBDS-258 dual-allowlist; cutover will set this to ['ES256']
    // once the metric in back/docs/DPOP_CUTOVER_RUNBOOK.md shows < 1%
    // RS256 sustained. Resolved at runtime by
    // SsoServerServiceProvider::resolveDpopAllowedAlgsConfig() from
    // SSO_DPOP_ALLOWED_ALGS — config:cache freezes any `env()` call
    // inside this file, so the env read lives in the provider.
    'dpop_allowed_algs' => ['ES256', 'RS256'],
];
