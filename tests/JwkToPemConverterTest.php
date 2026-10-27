<?php

namespace Dowardev\SsoServer\Tests;

use Dowardev\SsoServer\Jwt\JwkToPemConverter;
use InvalidArgumentException;
use ReflectionMethod;

class JwkToPemConverterTest extends TestCase
{
    private JwkToPemConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new JwkToPemConverter;
    }

    public function test_convert_returns_valid_pem_format(): void
    {
        $jwk = [
            'kty' => 'RSA',
            'n' => 'tqYtl-5i5i6tOGaSvS4M3z7Eq6AhgCJed7xKl65gI3MhSYq5GZrI1h7j0r6t2v8b1eBZ3Eq4Ohd3fGjqR5yS2Pl5v5gW9XRU3iO8hS4cA0eR6v8f7t3gH5k2l1j8mN7oP6qR4sA3bB9cC5dD1eE7fF2gG8hH6iI4jJ3kK1lL0mM9nN7oO5pP3qQ1rR8sS6tT4uU2vV0wW8xX6yY4zZ2aA0bB8cC6dD4eE2fF0gG7hH5iI3jJ1kK9lL7m',
            'e' => 'AQAB',
        ];

        $pem = $this->converter->convert($jwk);

        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pem);
        $this->assertStringEndsWith('-----END PUBLIC KEY-----', $pem);
    }

    public function test_base64_url_decode_handles_standard_base64(): void
    {
        $encoded = 'SGVsbG8gV29ybGQ';
        $decoded = $this->converter->base64UrlDecode($encoded);

        $this->assertEquals('Hello World', $decoded);
    }

    public function test_base64_url_decode_handles_url_safe_characters(): void
    {
        $standard = base64_encode('test-data-with-special-chars!@#');
        $urlSafe = strtr($standard, '+/', '-_');

        $decoded = $this->converter->base64UrlDecode(rtrim($urlSafe, '='));

        $this->assertEquals('test-data-with-special-chars!@#', $decoded);
    }

    public function test_base64_url_encode_produces_url_safe_output(): void
    {
        $data = 'test data with +/ characters';

        $result = JwkToPemConverter::base64UrlEncode($data);

        $this->assertStringNotContainsString('+', $result);
        $this->assertStringNotContainsString('/', $result);
        $this->assertStringNotContainsString('=', $result);
    }

    public function test_convert_for_ec_p256_emits_correct_subject_public_key_info(): void
    {
        // Fixture: a real EC P-256 key generated via openssl_pkey_new.
        // The expected PEM is what openssl_pkey_get_details() reports for
        // the same (x, y) pair, so a byte-for-byte match is the strictest
        // possible assertion that our hand-rolled ASN.1 matches OpenSSL.
        $jwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => '4yPUckJUSjT0rHbaqsaEKf41dyOA0OctKkrYmNr2Za8',
            'y' => 'MG_KCzPrsX_PAIbVMBG5xKd8y23LnVQW_-QygFUi3aQ',
        ];

        $expected = "-----BEGIN PUBLIC KEY-----\n".
            "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE4yPUckJUSjT0rHbaqsaEKf41dyOA\n".
            "0OctKkrYmNr2Za8wb8oLM+uxf88AhtUwEbnEp3zLbcudVBb/5DKAVSLdpA==\n".
            '-----END PUBLIC KEY-----';

        $pem = $this->converter->convert($jwk);

        $this->assertEquals($expected, $pem);

        // OpenSSL must accept our PEM and report the same curve/point — a
        // round-trip through openssl_pkey_get_details is the strongest
        // structural test that the SubjectPublicKeyInfo is well-formed.
        $key = openssl_pkey_get_public($pem);
        $this->assertNotFalse($key, 'OpenSSL failed to parse the EC PEM');

        $details = openssl_pkey_get_details($key);
        $this->assertSame('prime256v1', $details['ec']['curve_name']);
        $this->assertSame(
            $this->converter->base64UrlDecode($jwk['x']),
            $details['ec']['x']
        );
        $this->assertSame(
            $this->converter->base64UrlDecode($jwk['y']),
            $details['ec']['y']
        );
    }

    public function test_convert_rejects_unknown_kty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->converter->convert(['kty' => 'oct', 'k' => 'x']);
    }

    public function test_convert_rejects_unsupported_ec_curve(): void
    {
        // secp256k1 is a real curve but not on our allowlist; the converter
        // must reject it before the bytes reach the verifier, so a hostile
        // client cannot trick OpenSSL into using an exotic algorithm.
        $this->expectException(InvalidArgumentException::class);
        $this->converter->convert([
            'kty' => 'EC',
            'crv' => 'secp256k1',
            'x' => 'AAAAAAAAAAAAAAAAAAAAAA',
            'y' => 'AAAAAAAAAAAAAAAAAAAAAA',
        ]);
    }

    public function test_convert_rejects_ec_jwk_missing_required_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->converter->convert(['kty' => 'EC', 'crv' => 'P-256']);
    }

    public function test_convert_rejects_ec_jwk_with_wrong_coordinate_length(): void
    {
        // S-3 (LBDS-258 review): a P-256 JWK with x/y decoded to anything
        // other than 32 bytes must fail fast with a clear message, not
        // silently produce a PEM that OpenSSL rejects at import time.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('EC coordinates for P-256 must be exactly 32 bytes');
        $this->converter->convert([
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => 'AA', // 1 byte after base64url decode
            'y' => '4yPUckJUSjT0rHbaqsaEKf41dyOA0OctKkrYmNr2Za8', // 32 bytes
        ]);
    }

    public function test_normalize_ec_coordinate_accepts_31_byte_and_pads_to_32(): void
    {
        // Test the private normalizeEcCoordinate method via reflection.
        // This tests the fix for the 31-byte coordinate issue where a valid
        // P-256 coordinate with a leading 0x00 byte gets base64url-encoded
        // without that leading zero, resulting in a 31-byte decoded value.
        $method = new ReflectionMethod(JwkToPemConverter::class, 'normalizeEcCoordinate');
        $method->setAccessible(true);

        // 32 bytes - should pass through unchanged
        $coord32 = random_bytes(32);
        $result = $method->invoke($this->converter, $coord32, 32);
        $this->assertSame($coord32, $result);
        $this->assertSame(32, strlen($result));

        // 31 bytes - should be left-padded with a single 0x00 byte
        $coord31 = random_bytes(31);
        $result = $method->invoke($this->converter, $coord31, 32);
        $this->assertSame(32, strlen($result));
        $this->assertSame("\x00", $result[0]);
        $this->assertSame($coord31, substr($result, 1));
    }

    public function test_normalize_ec_coordinate_rejects_30_byte(): void
    {
        $method = new ReflectionMethod(JwkToPemConverter::class, 'normalizeEcCoordinate');
        $method->setAccessible(true);

        $coord30 = random_bytes(30);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('EC coordinates for P-256 must be exactly 32 bytes; got 30');
        $method->invoke($this->converter, $coord30, 32);
    }

    public function test_normalize_ec_coordinate_rejects_33_byte(): void
    {
        $method = new ReflectionMethod(JwkToPemConverter::class, 'normalizeEcCoordinate');
        $method->setAccessible(true);

        $coord33 = random_bytes(33);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('EC coordinates for P-256 must be exactly 32 bytes; got 33');
        $method->invoke($this->converter, $coord33, 32);
    }
}
