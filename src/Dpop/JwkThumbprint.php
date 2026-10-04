<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Dpop;

use InvalidArgumentException;

class JwkThumbprint
{
    /**
     * RFC 7638 — JSON Web Key (JWK) Thumbprint.
     *
     * For EC keys (§3.2) the required fields are `crv`, `kty`, `x`, `y` in
     * that exact order, with no whitespace and no other members. For RSA
     * keys (§3.3) the required fields are `e`, `kty`, `n` in that order.
     *
     * Insertion order in PHP array literals matches json_encode output, so
     * the field order in the literal is the contract here.
     */
    public static function compute(array $jwk): string
    {
        $kty = $jwk['kty'] ?? '';

        if ($kty === 'EC') {
            // EC is strict: every RFC 7638 §3.2 field must be present and
            // non-empty. Silently defaulting missing fields to '' would
            // produce a deterministic but meaningless thumbprint, which a
            // consumer could mistake for a valid binding.
            if (! isset($jwk['crv'], $jwk['x'], $jwk['y'])
                || $jwk['crv'] === '' || $jwk['x'] === '' || $jwk['y'] === '') {
                throw new InvalidArgumentException('EC JWK missing required fields (crv, x, y)');
            }

            $canonical = json_encode([
                'crv' => $jwk['crv'],
                'kty' => $jwk['kty'],
                'x' => $jwk['x'],
                'y' => $jwk['y'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif ($kty === 'RSA' || $kty === '') {
            // The historical behaviour (preserved for back-compat) accepted an
            // empty JWK array and produced the canonical serialization with
            // every field defaulted to ''. RSA tokens from before the
            // migration still hit this branch.
            $canonical = json_encode([
                'e' => $jwk['e'] ?? '',
                'kty' => $jwk['kty'] ?? 'RSA',
                'n' => $jwk['n'] ?? '',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            throw new InvalidArgumentException("Unsupported JWK kty for thumbprint: {$kty}");
        }

        return rtrim(
            strtr(base64_encode(hash('sha256', $canonical, true)), '+/', '-_'),
            '='
        );
    }
}
