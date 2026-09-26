<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Unit;

use App\Models\User;
use App\Services\JwtService;
use Tests\Support\DpopProofBuilder;
use Tests\TestCase;

/**
 * Unit tests for the jti claim on every kind of token JwtService issues.
 *
 * Covers LBDS-254: every issued token must carry a unique, canonical UUID v4
 * jti claim. The claim is emitted by `identifiedBy(...)` on the lcobucci/jwt
 * builder; these tests pin both the value format and the per-emission
 * uniqueness contract that RFC 7009 revocation and per-token audit rely on.
 */
class JwtServiceJtiTest extends TestCase
{
    private JwtService $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = app(JwtService::class);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->make(array_merge([
            'id' => 1,
            'user' => 'alice',
            'name' => 'Alice',
            'email' => 'alice@test.local',
            'encryption_level' => 'basic',
            'profile_version' => 1,
        ], $overrides));
    }

    private function extractJti(string $tokenString): ?string
    {
        $token = $this->jwt->parseAndValidateToken($tokenString);
        $this->assertNotNull($token, 'token must parse and validate');

        return $token->claims()->get('jti');
    }

    /**
     * Canonical UUID v4: 8-4-4-4-12 lowercase hex, version nibble = 4,
     * variant nibble ∈ {8,9,a,b}. This is the RFC 4122 layout and the
     * one Str::uuid()->toString() emits by default.
     */
    private function assertUuidV4(string $value, string $context = ''): void
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
        $this->assertMatchesRegularExpression(
            $pattern,
            $value,
            $context !== '' ? "{$context}: expected canonical UUID v4, got '{$value}'" : "expected canonical UUID v4, got '{$value}'"
        );
    }

    public function test_generate_token_bearer_includes_jti(): void
    {
        $result = $this->jwt->generateToken($this->makeUser());

        $this->assertArrayHasKey('token', $result);
        $jti = $this->extractJti($result['token']);
        $this->assertNotEmpty($jti);
        $this->assertUuidV4($jti, 'generateToken (Bearer)');
    }

    public function test_generate_token_dpop_includes_jti(): void
    {
        $dpop = DpopProofBuilder::generate();

        $result = $this->jwt->generateToken($this->makeUser(), $dpop->publicKey());

        $this->assertSame('DPoP', $result['token_type']);
        $jti = $this->extractJti($result['token']);
        $this->assertNotEmpty($jti);
        $this->assertUuidV4($jti, 'generateToken (DPoP)');
    }

    public function test_generate_token_for_jkt_refresh_includes_jti(): void
    {
        $jkt = DpopProofBuilder::generate()->jkt();

        $result = $this->jwt->generateTokenForJkt($this->makeUser(), $jkt);

        $this->assertSame('DPoP', $result['token_type']);
        $jti = $this->extractJti($result['token']);
        $this->assertNotEmpty($jti);
        $this->assertUuidV4($jti, 'generateTokenForJkt (refresh)');
    }

    public function test_generate_client_token_includes_jti(): void
    {
        $result = $this->jwt->generateClientToken('test_client', 'client_credentials');

        $jti = $this->extractJti($result['token']);
        $this->assertNotEmpty($jti);
        $this->assertUuidV4($jti, 'generateClientToken');
    }

    public function test_issue_profile_token_includes_jti(): void
    {
        $user = $this->makeUser(['profile_version' => 5]);

        $result = $this->jwt->issueProfileToken($user);

        $jti = $this->extractJti($result['token']);
        $this->assertNotEmpty($jti);
        $this->assertUuidV4($jti, 'issueProfileToken');
    }

    public function test_jti_is_canonical_uuid_v4_across_every_token_kind(): void
    {
        $user = $this->makeUser();
        $dpop = DpopProofBuilder::generate();

        $tokens = [
            'bearer' => $this->jwt->generateToken($user)['token'],
            'dpop_login' => $this->jwt->generateToken($user, $dpop->publicKey())['token'],
            'refresh_jkt' => $this->jwt->generateTokenForJkt($user, $dpop->jkt())['token'],
            'client_credentials' => $this->jwt->generateClientToken('cid')['token'],
            'profile' => $this->jwt->issueProfileToken($user)['token'],
        ];

        foreach ($tokens as $label => $tokenString) {
            $jti = $this->extractJti($tokenString);
            $this->assertUuidV4($jti, "jti for {$label}");
        }
    }

    public function test_consecutive_generate_token_calls_yield_distinct_jti(): void
    {
        $user = $this->makeUser();

        $a = $this->extractJti($this->jwt->generateToken($user)['token']);
        $b = $this->extractJti($this->jwt->generateToken($user)['token']);
        $c = $this->extractJti($this->jwt->generateToken($user)['token']);

        $this->assertNotSame($a, $b, 'second issuance must mint a fresh jti');
        $this->assertNotSame($b, $c, 'third issuance must mint a fresh jti');
        $this->assertNotSame($a, $c, 'third issuance must differ from the first');
    }

    public function test_refresh_rotation_yields_distinct_jti_from_original(): void
    {
        $user = $this->makeUser();

        $original = $this->extractJti($this->jwt->generateToken($user)['token']);
        $rotated = $this->extractJti($this->jwt->generateTokenForJkt($user, null)['token']);

        $this->assertNotSame(
            $original,
            $rotated,
            'refresh rotation must issue a fresh jti so the old token can be revoked independently'
        );
    }
}
