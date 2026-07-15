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
];
