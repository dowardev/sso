<?php

namespace Dowardev\SsoServer\Tests;

use Dowardev\SsoServer\Dpop\JwkThumbprint;

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
}
