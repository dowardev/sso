<?php

// SPDX-License-Identifier: AGPL-3.0-only

return [
    // Top-level key paths are kept here as defaults for code that still reads
    // them (e.g. AuthService::getJwks()). JwtService (the token builder /
    // verifier) intentionally ignores these and resolves keys from
    // `keys[0]` instead — see JwtService::__construct(). Rotation is
    // config-only via the `keys` array below.
    'private_key_path' => base_path(env('JWT_PRIVATE_KEY_PATH', 'keys/private.pem')),
    'public_key_path' => base_path(env('JWT_PUBLIC_KEY_PATH', 'keys/public.pem')),
    'token_expiration' => env('JWT_TOKEN_EXPIRATION', 3600),
    'refresh_expiration' => env('JWT_REFRESH_EXPIRATION', 2592000), // 30 días
    'refresh_inactivity' => env('JWT_REFRESH_INACTIVITY', 604800), // 7 días sin uso
    'issuer' => env('JWT_ISSUER', config('app.url')),
    'algorithm' => 'RS256',
    // Key identifier (kid) embedded in every JWT header.
    // Forward-compatible: `keys` below is the multi-key rotation primitive
    // (LBDS-258). Today it ships a single entry mirroring `kid`; rotation is a
    // config-only change — see docs/AUTHENTICATION.md.
    'kid' => env('JWT_KID', 'sso-key-1'),
    'keys' => [
        [
            'kid' => env('JWT_KID', 'sso-key-1'),
            'private_key_path' => base_path(env('JWT_PRIVATE_KEY_PATH', 'keys/private.pem')),
            'public_key_path' => base_path(env('JWT_PUBLIC_KEY_PATH', 'keys/public.pem')),
        ],
    ],
];
