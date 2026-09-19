<?php

namespace Tests\Unit;

use App\Support\OriginNormalizer;
use PHPUnit\Framework\TestCase;

class OriginNormalizerTest extends TestCase
{
    public function test_normalizes_scheme_and_host_to_lowercase(): void
    {
        $this->assertSame(
            'https://app.example.com',
            OriginNormalizer::normalize('HTTPS://APP.Example.COM')
        );
    }

    public function test_ignores_path_when_extracting_the_origin(): void
    {
        $this->assertSame(
            'http://localhost:8102',
            OriginNormalizer::normalize('http://localhost:8102/auth/callback')
        );
    }

    public function test_drops_default_ports(): void
    {
        $this->assertSame('https://app.example.com', OriginNormalizer::normalize('https://app.example.com:443'));
        $this->assertSame('http://app.example.com', OriginNormalizer::normalize('http://app.example.com:80'));
    }

    public function test_keeps_non_default_ports(): void
    {
        $this->assertSame('http://localhost:8102', OriginNormalizer::normalize('http://localhost:8102'));
        $this->assertSame('https://app.example.com:8443', OriginNormalizer::normalize('https://app.example.com:8443'));
    }

    public function test_returns_null_for_custom_scheme(): void
    {
        $this->assertNull(OriginNormalizer::normalize('com.example.app://auth/callback'));
    }

    public function test_returns_null_for_empty_or_hostless_values(): void
    {
        $this->assertNull(OriginNormalizer::normalize(null));
        $this->assertNull(OriginNormalizer::normalize(''));
        $this->assertNull(OriginNormalizer::normalize('not-an-origin'));
        $this->assertNull(OriginNormalizer::normalize('http://'));
    }

    public function test_matches_treats_default_port_as_equivalent(): void
    {
        $this->assertTrue(OriginNormalizer::matches('https://app.example.com', ['https://app.example.com:443']));
        $this->assertTrue(OriginNormalizer::matches('http://localhost:8102', ['http://localhost:8102']));
    }

    public function test_matches_is_false_when_origin_is_absent(): void
    {
        $this->assertFalse(OriginNormalizer::matches('http://localhost:8102', ['http://localhost:8101']));
        $this->assertFalse(OriginNormalizer::matches('http://localhost:8102', []));
    }

    public function test_matches_is_false_for_null_or_non_http_origin(): void
    {
        $this->assertFalse(OriginNormalizer::matches(null, ['https://app.example.com']));
        $this->assertFalse(OriginNormalizer::matches('com.example.app://auth', ['https://app.example.com']));
    }

    public function test_normalize_all_discards_non_http_entries(): void
    {
        $this->assertSame(
            ['https://app.example.com', 'http://localhost:8102'],
            OriginNormalizer::normalizeAll([
                'https://app.example.com',
                'http://localhost:8102',
                'com.example.app://auth/callback',
                'ftp://files.example.com',
                null,
            ])
        );
    }
}
