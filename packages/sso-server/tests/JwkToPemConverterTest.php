<?php

namespace Dowardev\SsoServer\Tests;

use Dowardev\SsoServer\Jwt\JwkToPemConverter;

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
}
