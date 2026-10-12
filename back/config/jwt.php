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
    // OIDC id_token lifetime: the spec recommends a short window since the
    // id_token is meant to be verified once at login, not reused on every
    // API call. 10 minutes matches what Keycloak / Auth0 ship out of the box.
    'id_token_expiration' => env('JWT_ID_TOKEN_EXPIRATION', 600),
    // OIDC base URL used to build the discovery doc endpoint URLs. The
    // discovery response MUST advertise URLs an RP can reach, so this
    // should be the externally-visible origin (e.g. https://sso.example.com),
    // not a path-rewriting proxy. When null, the discovery endpoint
    // derives the base from the current request (scheme + host + the
    // /api prefix); that breaks behind path-rewriting proxies, which
    // is why this knob exists. Set OIDC_BASE_URL=https://sso.example.com
    // in the environment to pin it.
    'oidc_base_url' => env('OIDC_BASE_URL'),
    'refresh_expiration' => env('JWT_REFRESH_EXPIRATION', 2592000), // 30 días
    'refresh_inactivity' => env('JWT_REFRESH_INACTIVITY', 604800), // 7 días sin uso
    'issuer' => env('JWT_ISSUER', config('app.url')),
    'algorithm' => 'RS256',

    // Algorithms accepted by the DPoP proof verifier during the gradual
    // RSA-2048 → ECDSA P-256 migration tracked by LBDS-290. Default is the
    // dual-allowlist of the post-LBDS-258 rollout; cutover will set this
    // to ['ES256'] (in config or via the DPOP_ALLOWED_ALGS env var) once
    // the metric in back/docs/DPOP_CUTOVER_RUNBOOK.md shows < 1% RS256
    // sustained for 30 days.
    //
    // The env var is NOT read here on purpose: `env()` inside a config
    // file gets frozen by `php artisan config:cache`, which would defeat
    // the whole point of the runtime config flag. The DPOP_ALLOWED_ALGS
    // resolution lives in AppServiceProvider::bindDpopAllowedAlgs() so
    // the value is read on every request.
    'dpop_allowed_algs' => ['ES256', 'RS256'],

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
