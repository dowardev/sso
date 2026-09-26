<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Tests\Unit;

use DateTimeImmutable;
use Dowardev\SsoServer\Exceptions\JwksFetchException;
use Dowardev\SsoServer\Jwt\JwksFetcher;
use Dowardev\SsoServer\Jwt\JwtValidator;
use Dowardev\SsoServer\Tests\TestCase;
use Dowardev\SsoServer\Types\SsoConfig;
use Illuminate\Support\Facades\Cache;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

class JwtValidatorKidTest extends TestCase
{
    private string $privateKeyPem = '';

    private string $publicKeyPem = '';

    /**
     * Anonymous fake that records which lookup method the validator called
     * and returns a configurable PEM. Extends JwksFetcher so JwtValidator's
     * constructor type-check passes. Overrides every method that would
     * touch the parent's readonly dependencies (which are intentionally
     * not initialised here).
     *
     * @var object{getPublicKeyPemCalls:int,getPublicKeyPemByKidCalls:int,primaryPem:string,pemsByKid:array<string,string>}
     */
    private object $fakeFetcher;

    private JwtValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $keyResource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($keyResource, $this->privateKeyPem);
        $this->publicKeyPem = openssl_pkey_get_details($keyResource)['key'];

        $primaryPem = $this->publicKeyPem;
        $fake = new class extends JwksFetcher
        {
            public int $getPublicKeyPemCalls = 0;

            public int $getPublicKeyPemByKidCalls = 0;

            public string $primaryPem = '';

            /** @var array<string, string> */
            public array $pemsByKid = [];

            public function __construct()
            {
                // Skip parent::__construct — we override every method that
                // would touch its readonly dependencies.
            }

            public function getPublicKeyPem(): string
            {
                $this->getPublicKeyPemCalls++;

                return $this->primaryPem;
            }

            public function getPublicKeyPemByKid(string $kid): string
            {
                $this->getPublicKeyPemByKidCalls++;

                if (! isset($this->pemsByKid[$kid])) {
                    throw JwksFetchException::unknownKid($kid);
                }

                return $this->pemsByKid[$kid];
            }

            public function clearCache(): void
            {
                // no-op for tests
            }
        };

        $fake->primaryPem = $primaryPem;
        $fake->pemsByKid = ['test-kid' => $primaryPem];

        /** @var object{getPublicKeyPemCalls:int,getPublicKeyPemByKidCalls:int,primaryPem:string,pemsByKid:array<string,string>} $fake */
        $this->fakeFetcher = $fake;

        $config = SsoConfig::fromConfig();
        $this->validator = new JwtValidator($config, $this->fakeFetcher);
    }

    public function test_validate_with_kid_header_resolves_via_kid(): void
    {
        $token = $this->buildToken('test-kid');

        $parsed = $this->validator->validate($token);

        $this->assertSame('test-kid', $parsed->headers()->get('kid'));
        $this->assertSame(1, $this->fakeFetcher->getPublicKeyPemByKidCalls);
        $this->assertSame(0, $this->fakeFetcher->getPublicKeyPemCalls);
    }

    public function test_validate_without_kid_falls_back_to_primary(): void
    {
        $token = $this->buildToken(null);

        $parsed = $this->validator->validate($token);

        $this->assertNull($parsed->headers()->get('kid'));
        $this->assertSame(1, $this->fakeFetcher->getPublicKeyPemCalls);
        $this->assertSame(0, $this->fakeFetcher->getPublicKeyPemByKidCalls);
    }

    public function test_validate_with_unknown_kid_throws(): void
    {
        $token = $this->buildToken('unknown');

        $this->expectException(JwksFetchException::class);
        $this->expectExceptionMessage('unknown');

        $this->validator->validate($token);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function buildToken(?string $kid): string
    {
        $config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($this->privateKeyPem),
            InMemory::plainText($this->publicKeyPem)
        );

        $builder = $config->builder()
            ->issuedBy((string) config('sso.jwt_issuer'))
            ->issuedAt(new DateTimeImmutable);

        if ($kid !== null) {
            $builder = $builder->withHeader('kid', $kid);
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }
}
