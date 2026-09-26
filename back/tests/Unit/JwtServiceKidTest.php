<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Unit;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Support\Str;
use Tests\Support\DpopProofBuilder;
use Tests\TestCase;

/**
 * Unit tests for the kid header on every kind of token JwtService issues, and
 * for the kid-aware verification path in parseAndValidateToken.
 *
 * Covers LBDS-256 Task 1 (back): JWTs emitted by JwtService must carry header
 * `kid` with the value from config('jwt.kid'), and parseAndValidateToken must
 * (a) accept a token with a matching kid, (b) fall back to the primary key
 * when kid is absent (back-compat), and (c) reject an unknown kid.
 *
 * The lcobucci/jwt headers() DataSet returns the kid as whatever was written
 * via withHeader — for our string writes that's a string. We cast to string in
 * the assertion helper to keep tests robust against a future middleware change.
 */
class JwtServiceKidTest extends TestCase
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

    /**
     * Parse a compact JWS and read the kid header. Asserts the header is
     * present and a non-empty string; returns the kid as a string.
     */
    private function extractKid(string $tokenString): string
    {
        $token = $this->jwt->parseAndValidateToken($tokenString);
        $this->assertNotNull($token, 'token must parse and validate');

        $kid = $token->headers()->get('kid');
        $this->assertNotNull($kid, 'token must carry a kid header');
        $this->assertIsString($kid, 'kid header must be a string');

        return $kid;
    }

    // --- builders: per-builder header presence -----------------------------------

    public function test_build_token_user_bearer_carries_configured_kid(): void
    {
        $configuredKid = (string) config('jwt.kid');

        $result = $this->jwt->generateToken($this->makeUser());

        $this->assertSame($configuredKid, $this->extractKid($result['token']));
    }

    public function test_build_token_user_dpop_carries_configured_kid(): void
    {
        $configuredKid = (string) config('jwt.kid');
        $dpop = DpopProofBuilder::generate();

        $result = $this->jwt->generateToken($this->makeUser(), $dpop->publicKey());

        $this->assertSame('DPoP', $result['token_type']);
        $this->assertSame($configuredKid, $this->extractKid($result['token']));
    }

    public function test_build_token_user_jkt_refresh_carries_configured_kid(): void
    {
        $configuredKid = (string) config('jwt.kid');
        $dpop = DpopProofBuilder::generate();

        $result = $this->jwt->generateTokenForJkt($this->makeUser(), $dpop->jkt());

        $this->assertSame('DPoP', $result['token_type']);
        $this->assertSame($configuredKid, $this->extractKid($result['token']));
    }

    public function test_generate_client_token_carries_configured_kid(): void
    {
        $configuredKid = (string) config('jwt.kid');

        $result = $this->jwt->generateClientToken('test_client', 'client_credentials');

        $this->assertSame($configuredKid, $this->extractKid($result['token']));
    }

    public function test_issue_profile_token_carries_configured_kid(): void
    {
        $configuredKid = (string) config('jwt.kid');
        $user = $this->makeUser(['profile_version' => 5]);

        $result = $this->jwt->issueProfileToken($user);

        $this->assertSame($configuredKid, $this->extractKid($result['token']));
    }

    // --- parseAndValidateToken: kid-aware resolution -----------------------------

    public function test_parse_accepts_token_with_matching_kid(): void
    {
        $configuredKid = (string) config('jwt.kid');

        $tokenString = $this->jwt->generateToken($this->makeUser())['token'];

        $parsed = $this->jwt->parseAndValidateToken($tokenString);

        $this->assertNotNull($parsed, 'token with matching kid must validate');
        $this->assertSame($configuredKid, (string) $parsed->headers()->get('kid'));
    }

    /**
     * Back-compat: a token whose header carries no kid must still validate
     * against the primary key (D4 fallback in odd/tasks/lbds-256-kid-header-jwts.md).
     *
     * Implementation note: we have to bypass JwtService to construct the
     * "no kid" token because every builder now writes the kid header. We
     * build the unsigned claim set, sign it manually against the configured
     * primary private key, and skip withHeader('kid', ...). That is the exact
     * shape of tokens issued by previous versions of this service.
     */
    public function test_parse_falls_back_to_primary_key_when_kid_header_is_absent(): void
    {
        $config = $this->jwt->getJwtConfig();
        $now = time();
        $expiresAt = $now + 600;

        $builder = $config->builder()
            ->issuedBy($this->jwt->getIssuer())
            ->identifiedBy((string) Str::uuid())
            ->issuedAt(new \DateTimeImmutable('@'.$now))
            ->expiresAt(new \DateTimeImmutable('@'.$expiresAt))
            ->relatedTo('1')
            ->withClaim('user', ['id' => 1]);

        // Deliberately do NOT call withHeader('kid', ...) — this simulates a
        // token minted by the pre-LBDS-256 JwtService.
        $legacyToken = $builder->getToken($config->signer(), $config->signingKey());

        $parsed = $this->jwt->parseAndValidateToken($legacyToken->toString());

        $this->assertNotNull($parsed, 'legacy token (no kid) must still validate via primary key fallback');
        $this->assertNull($parsed->headers()->get('kid'), 'sanity: this token has no kid header');
    }

    /**
     * Unknown kid: parseAndValidateToken must return null (same shape as the
     * existing validation-failure contract), never throw. We craft a token
     * signed by the configured primary private key but with an unknown kid
     * header so the only thing it should reject on is the kid mismatch.
     */
    public function test_parse_returns_null_when_kid_header_is_unknown(): void
    {
        $config = $this->jwt->getJwtConfig();
        $now = time();
        $expiresAt = $now + 600;

        $builder = $config->builder()
            ->issuedBy($this->jwt->getIssuer())
            ->identifiedBy((string) Str::uuid())
            ->issuedAt(new \DateTimeImmutable('@'.$now))
            ->expiresAt(new \DateTimeImmutable('@'.$expiresAt))
            ->relatedTo('1')
            ->withClaim('user', ['id' => 1]);

        // Force a kid that the configured key set does not know about.
        $builder = $builder->withHeader('kid', 'definitely-not-a-known-kid');

        $bogusToken = $builder->getToken($config->signer(), $config->signingKey());

        $parsed = $this->jwt->parseAndValidateToken($bogusToken->toString());

        $this->assertNull(
            $parsed,
            'token signed with the primary key but carrying an unknown kid must be rejected'
        );
    }

    // --- getKid helper ----------------------------------------------------------

    public function test_get_kid_returns_configured_value(): void
    {
        $this->assertSame((string) config('jwt.kid'), $this->jwt->getKid());
    }

    // --- edge cases: malformed / hostile kid values -----------------------------

    /**
     * An empty-string kid header must be treated as "no kid" — the predicate
     * in resolveVerificationKey() rejects empty strings, falling back to the
     * primary key (back-compat path). Without this guard, an empty kid would
     * be looked up in activeKeys and always miss.
     */
    public function test_parse_with_empty_string_kid_returns_primary_key(): void
    {
        $config = $this->jwt->getJwtConfig();
        $now = time();
        $expiresAt = $now + 600;

        $builder = $config->builder()
            ->issuedBy($this->jwt->getIssuer())
            ->identifiedBy((string) Str::uuid())
            ->issuedAt(new \DateTimeImmutable('@'.$now))
            ->expiresAt(new \DateTimeImmutable('@'.$expiresAt))
            ->relatedTo('1')
            ->withClaim('user', ['id' => 1])
            ->withHeader('kid', '');

        $token = $builder->getToken($config->signer(), $config->signingKey());

        $parsed = $this->jwt->parseAndValidateToken($token->toString());

        $this->assertNotNull($parsed, 'empty kid must fall back to primary key, not be rejected');
    }

    /**
     * Whitespace kid is not a configured kid and not the empty-string fallback,
     * so the resolver must reject it just like any unknown kid (returns null).
     */
    public function test_parse_with_whitespace_kid_treated_as_unknown(): void
    {
        $config = $this->jwt->getJwtConfig();
        $now = time();
        $expiresAt = $now + 600;

        $builder = $config->builder()
            ->issuedBy($this->jwt->getIssuer())
            ->identifiedBy((string) Str::uuid())
            ->issuedAt(new \DateTimeImmutable('@'.$now))
            ->expiresAt(new \DateTimeImmutable('@'.$expiresAt))
            ->relatedTo('1')
            ->withClaim('user', ['id' => 1])
            ->withHeader('kid', '   ');

        $token = $builder->getToken($config->signer(), $config->signingKey());

        $parsed = $this->jwt->parseAndValidateToken($token->toString());

        $this->assertNull(
            $parsed,
            'whitespace kid must not match any configured kid and must be rejected'
        );
    }

    /**
     * A kid containing the cache-separator character (':') must not collide
     * with cache keys on the package side (`sso_jwt_public_key:<kid>`).
     * Back-side parsing only matches against the in-memory activeKeys set,
     * so 'foo:bar' is just an unknown kid — but we pin the behaviour so the
     * behaviour does not silently drift toward a substring match.
     */
    public function test_parse_with_kid_containing_colon_is_rejected(): void
    {
        $config = $this->jwt->getJwtConfig();
        $now = time();
        $expiresAt = $now + 600;

        $builder = $config->builder()
            ->issuedBy($this->jwt->getIssuer())
            ->identifiedBy((string) Str::uuid())
            ->issuedAt(new \DateTimeImmutable('@'.$now))
            ->expiresAt(new \DateTimeImmutable('@'.$expiresAt))
            ->relatedTo('1')
            ->withClaim('user', ['id' => 1])
            ->withHeader('kid', 'foo:bar');

        $token = $builder->getToken($config->signer(), $config->signingKey());

        $parsed = $this->jwt->parseAndValidateToken($token->toString());

        $this->assertNull(
            $parsed,
            'kid containing `:` must be treated as unknown, not matched as a cache segment'
        );
    }

    /**
     * Empty config('jwt.keys') is a deployment-time misconfiguration; we must
     * fail loud at boot (RuntimeException), not silently produce tokens that
     * no consumer can verify.
     */
    public function test_active_keys_empty_throws_at_boot(): void
    {
        config(['jwt.keys' => []]);

        $this->expectException(\RuntimeException::class);

        // new JwtService directly invokes the constructor with the
        // just-modified config (the container may already hold an instance
        // resolved earlier in the test, whose config snapshot is stale).
        new JwtService;
    }
}
