<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Tests\Unit;

use Dowardev\SsoServer\Exceptions\JwksFetchException;
use Dowardev\SsoServer\Jwt\JwksFetcher;
use Dowardev\SsoServer\Jwt\JwkToPemConverter;
use Dowardev\SsoServer\Tests\TestCase;
use Dowardev\SsoServer\Types\SsoConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class JwksFetcherKidTest extends TestCase
{
    private JwksFetcher $fetcher;

    private string $primaryPublicKeyPem;

    private string $secondaryPublicKeyPem;

    /**
     * @var array<string, mixed>
     */
    private array $primaryJwk;

    /**
     * @var array<string, mixed>
     */
    private array $secondaryJwk;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $keyResource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $primaryDetails = openssl_pkey_get_details($keyResource);
        $this->primaryPublicKeyPem = $primaryDetails['key'];

        $keyResource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $secondaryDetails = openssl_pkey_get_details($keyResource);
        $this->secondaryPublicKeyPem = $secondaryDetails['key'];

        $this->primaryJwk = $this->buildJwk('primary-kid', $this->primaryPublicKeyPem);
        $this->secondaryJwk = $this->buildJwk('secondary-kid', $this->secondaryPublicKeyPem);

        $config = SsoConfig::fromConfig();
        $this->fetcher = new JwksFetcher($config, new JwkToPemConverter);
    }

    public function test_get_public_key_pem_by_kid_returns_matching_key(): void
    {
        Http::fake([
            'http://localhost:8001/api/.well-known/jwks' => Http::response([
                'keys' => [$this->primaryJwk, $this->secondaryJwk],
            ], 200),
        ]);

        $pem = $this->fetcher->getPublicKeyPemByKid('secondary-kid');

        $this->assertSame(trim($this->secondaryPublicKeyPem), trim($pem));
    }

    public function test_get_public_key_pem_by_kid_uses_cache_on_second_call(): void
    {
        Http::fake([
            'http://localhost:8001/api/.well-known/jwks' => Http::response([
                'keys' => [$this->primaryJwk, $this->secondaryJwk],
            ], 200),
        ]);

        $first = $this->fetcher->getPublicKeyPemByKid('primary-kid');
        $second = $this->fetcher->getPublicKeyPemByKid('primary-kid');

        $this->assertSame($first, $second);
        $this->assertSame(trim($this->primaryPublicKeyPem), trim($second));
        Http::assertSentCount(1);
        $this->assertTrue(Cache::has('sso_jwt_public_key:primary-kid'));
    }

    public function test_get_public_key_pem_by_kid_throws_for_unknown_kid(): void
    {
        Http::fake([
            'http://localhost:8001/api/.well-known/jwks' => Http::response([
                'keys' => [$this->primaryJwk, $this->secondaryJwk],
            ], 200),
        ]);

        $this->expectException(JwksFetchException::class);
        $this->expectExceptionMessage('unknown-kid');

        $this->fetcher->getPublicKeyPemByKid('unknown-kid');
    }

    public function test_clear_cache_forgets_per_kid_entries(): void
    {
        Http::fake([
            'http://localhost:8001/api/.well-known/jwks' => Http::response([
                'keys' => [$this->primaryJwk, $this->secondaryJwk],
            ], 200),
        ]);

        $this->fetcher->getPublicKeyPemByKid('primary-kid');
        $this->fetcher->getPublicKeyPemByKid('secondary-kid');

        $this->assertTrue(Cache::has('sso_jwt_public_key:primary-kid'));
        $this->assertTrue(Cache::has('sso_jwt_public_key:secondary-kid'));

        $this->fetcher->clearCache();

        $this->assertFalse(Cache::has('sso_jwt_public_key:primary-kid'));
        $this->assertFalse(Cache::has('sso_jwt_public_key:secondary-kid'));
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildJwk(string $kid, string $publicPem): array
    {
        $keyResource = openssl_pkey_get_public($publicPem);
        $details = openssl_pkey_get_details($keyResource);

        return [
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => JwkToPemConverter::base64UrlEncode($details['rsa']['n']),
            'e' => JwkToPemConverter::base64UrlEncode($details['rsa']['e']),
        ];
    }
}
