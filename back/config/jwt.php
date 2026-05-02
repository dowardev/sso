<?php

return [
    'private_key_path' => base_path(env('JWT_PRIVATE_KEY_PATH', 'keys/private.pem')),
    'public_key_path' => base_path(env('JWT_PUBLIC_KEY_PATH', 'keys/public.pem')),
    'token_expiration' => env('JWT_TOKEN_EXPIRATION', 3600),
    'refresh_expiration' => env('JWT_REFRESH_EXPIRATION', 86400),
    'issuer' => env('JWT_ISSUER', 'http://localhost:8001'),
    'algorithm' => 'RS256',
];