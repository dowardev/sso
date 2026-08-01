<?php

// SPDX-License-Identifier: AGPL-3.0-only

return [
    'private_key_path' => base_path(env('JWT_PRIVATE_KEY_PATH', 'keys/private.pem')),
    'public_key_path' => base_path(env('JWT_PUBLIC_KEY_PATH', 'keys/public.pem')),
    'token_expiration' => env('JWT_TOKEN_EXPIRATION', 3600),
    'refresh_expiration' => env('JWT_REFRESH_EXPIRATION', 2592000), // 30 días
    'refresh_inactivity' => env('JWT_REFRESH_INACTIVITY', 604800), // 7 días sin uso
    'issuer' => env('JWT_ISSUER', config('app.url')),
    'algorithm' => 'RS256',
];
