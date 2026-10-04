<?php

namespace Dowardev\SsoServer\Tests;

use Dowardev\SsoServer\Dpop\JwkThumbprint;
use InvalidArgumentException;

class JwkThumbprintTest extends TestCase
{
    public function test_compute_returns_base64url_encoded_sha256_of_canonical_json(): void
    {
        $jwk = [
            'kty' => 'RSA',
            'n' => 'test-modulus',
            'e' => 'AQAB',
        ];

        $result = JwkThumbprint::compute($jwk);

        $canonical = json_encode([
            'e' => 'AQAB',
            'kty' => 'RSA',
            'n' => 'test-modulus',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $expected = rtrim(strtr(base64_encode(hash('sha256', $canonical, true)), '+/', '-_'), '=');

        $this->assertEquals($expected, $result);
    }

    public function test_compute_uses_alphabetical_key_order(): void
    {
        $jwk1 = ['kty' => 'RSA', 'n' => 'abc', 'e' => 'AQAB'];
        $jwk2 = ['e' => 'AQAB', 'n' => 'abc', 'kty' => 'RSA'];

        $this->assertEquals(JwkThumbprint::compute($jwk1), JwkThumbprint::compute($jwk2));
    }

    public function test_compute_handles_missing_fields_with_defaults(): void
    {
        $jwk = [];

        $result = JwkThumbprint::compute($jwk);

        $canonical = json_encode([
            'e' => '',
            'kty' => 'RSA',
            'n' => '',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $expected = rtrim(strtr(base64_encode(hash('sha256', $canonical, true)), '+/', '-_'), '=');

        $this->assertEquals($expected, $result);
    }

    public function test_compute_for_ec_p256_uses_rfc_7638_field_order(): void
    {
        // RFC 7638 §3.2 — EC required fields are `crv`, `kty`, `x`, `y` in
        // that exact order. The thumbprint MUST change if any other order is
        // used; the test pins the canonical serialization byte-for-byte.
        $jwk = [
            'crv' => 'P-256',
            'kty' => 'EC',
            'x' => 'ec-x-coordinate',
            'y' => 'ec-y-coordinate',
        ];

        $result = JwkThumbprint::compute($jwk);

        $canonical = json_encode([
            'crv' => 'P-256',
            'kty' => 'EC',
            'x' => 'ec-x-coordinate',
            'y' => 'ec-y-coordinate',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $expected = rtrim(strtr(base64_encode(hash('sha256', $canonical, true)), '+/', '-_'), '=');

        $this->assertEquals($expected, $result);
    }

    public function test_ec_and_rsa_thumbprints_differ_for_same_byte_arrays(): void
    {
        // Defensive: a migration that accidentally reuses the RSA branch for
        // EC JWKs would produce a colliding thumbprint. The x/y coordinates
        // here deliberately spell out the canonical field names; if the EC
        // branch accidentally serialized them as { e, kty, n } the hashes
        // would match and the test would catch the regression.
        $rsa = [
            'kty' => 'RSA',
            'e' => 'AQAB',
            'n' => 'shared-bytes',
        ];
        $ec = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => 'AQAB',
            'y' => 'shared-bytes',
        ];

        $this->assertNotEquals(
            JwkThumbprint::compute($rsa),
            JwkThumbprint::compute($ec)
        );
    }

    public function test_compute_rejects_unsupported_kty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JwkThumbprint::compute(['kty' => 'oct', 'k' => 'symmetric']);
    }

    public function test_compute_rejects_incomplete_ec_jwk(): void
    {
        // S-2 (LBDS-258 review): the EC branch must NOT silently default
        // missing fields to '' — a consumer calling compute() directly
        // with a partial EC JWK would otherwise get a deterministic
        // thumbprint over a meaningless string.
        $this->expectException(InvalidArgumentException::class);
        JwkThumbprint::compute(['kty' => 'EC', 'crv' => 'P-256']);
    }

    public function test_compute_rejects_ec_jwk_with_empty_required_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JwkThumbprint::compute(['kty' => 'EC', 'crv' => 'P-256', 'x' => '', 'y' => 'something']);
    }
}
