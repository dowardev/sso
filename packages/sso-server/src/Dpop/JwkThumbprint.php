<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Dpop;

class JwkThumbprint
{
    public static function compute(array $jwk): string
    {
        $canonical = json_encode([
            'e' => $jwk['e'] ?? '',
            'kty' => $jwk['kty'] ?? 'RSA',
            'n' => $jwk['n'] ?? '',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return rtrim(
            strtr(base64_encode(hash('sha256', $canonical, true)), '+/', '-_'),
            '='
        );
    }
}
