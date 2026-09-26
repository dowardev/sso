<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\SsoClient;
use App\Models\User;
use App\Models\UserRefreshToken;
use App\Services\JwtService;
use App\Services\OAuth2Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\DpopProofBuilder;
use Tests\TestCase;

/**
 * Feature tests for the jti claim on access tokens issued by the live HTTP
 * endpoints. Pinned by LBDS-254 (jti único en access tokens).
 *
 * Each issued token must carry a canonical UUID v4 jti, every emission mints a
 * fresh one (including refresh rotations), and the format is exactly the
 * RFC 4122 layout so downstream tooling (logs, audit, future RFC 7009
 * revocation endpoint) can treat jti as an opaque identifier.
 */
class JwtJtiClaimTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected SsoClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'jti@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->client = SsoClient::create([
            'name' => 'Test App',
            'client_id' => 'jti-test-app',
            'redirect_url' => 'https://app.example.com/auth/callback',
            'allowed_origins' => ['https://app.example.com'],
            'grant_types' => ['authorization_code'],
            'active' => true,
        ]);
    }

    /**
     * Canonical UUID v4 layout (RFC 4122): 8-4-4-4-12 lowercase hex with the
     * version nibble set to 4 and the variant nibble in {8,9,a,b}. This is
     * exactly what Str::uuid()->toString() emits.
     */
    private function assertUuidV4(string $value, string $context = ''): void
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
        $message = $context !== ''
            ? "{$context}: expected canonical UUID v4, got '{$value}'"
            : "expected canonical UUID v4, got '{$value}'";

        $this->assertMatchesRegularExpression($pattern, $value, $message);
    }

    private function extractJti(string $tokenString): string
    {
        $parsed = app(JwtService::class)->parseAndValidateToken($tokenString);
        $this->assertNotNull($parsed, 'token must parse and validate under the current signing key');

        $jti = $parsed->claims()->get('jti');
        $this->assertNotNull($jti, 'jti claim must be present');
        $this->assertIsString($jti, 'jti claim must be a string');

        return $jti;
    }

    // --- POST /api/login ----------------------------------------------------------

    public function test_login_response_token_carries_uuid_v4_jti(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'jti@example.com',
            'password' => 'password123',
            'client_id' => 'jti-test-app',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'token_type', 'expires_in']);

        $jti = $this->extractJti($response->json('token'));
        $this->assertUuidV4($jti, 'login access token');
    }

    public function test_two_logins_for_same_user_mint_distinct_jti(): void
    {
        $a = $this->postJson('/api/login', [
            'email' => 'jti@example.com',
            'password' => 'password123',
            'client_id' => 'jti-test-app',
        ])->assertOk();

        $b = $this->postJson('/api/login', [
            'email' => 'jti@example.com',
            'password' => 'password123',
            'client_id' => 'jti-test-app',
        ])->assertOk();

        $jtiA = $this->extractJti($a->json('token'));
        $jtiB = $this->extractJti($b->json('token'));

        $this->assertNotSame($jtiA, $jtiB, 'each login must mint a fresh jti');
    }

    // --- POST /api/oauth/token (authorization_code) --------------------------------

    public function test_oauth_token_authorization_code_grant_carries_uuid_v4_jti(): void
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $auth = app(OAuth2Service::class)->createAuthorizationCode(
            $this->user,
            $this->client,
            [
                'redirect_uri' => 'https://app.example.com/auth/callback',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]
        );

        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'jti-test-app',
            'code' => $auth['code'],
            'redirect_uri' => 'https://app.example.com/auth/callback',
            'code_verifier' => $verifier,
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'token_type', 'expires_in', 'refresh_token']);

        $jti = $this->extractJti($response->json('token'));
        $this->assertUuidV4($jti, 'oauth/token (authorization_code)');
    }

    // --- POST /api/oauth/token (refresh_token, DPoP-bound) ------------------------

    public function test_oauth_token_refresh_grant_mints_new_jti_different_from_prior_token(): void
    {
        $builder = DpopProofBuilder::generate();
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $auth = app(OAuth2Service::class)->createAuthorizationCode(
            $this->user,
            $this->client,
            [
                'redirect_uri' => 'https://app.example.com/auth/callback',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]
        );

        // Initial exchange mints a refresh token bound to the builder's DPoP jkt.
        $initial = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'jti-test-app',
            'code' => $auth['code'],
            'redirect_uri' => 'https://app.example.com/auth/callback',
            'code_verifier' => $verifier,
            'dpop_public_key' => $builder->publicKey(),
        ])->assertOk();

        $initialJti = $this->extractJti($initial->json('token'));
        $this->assertUuidV4($initialJti, 'initial token');

        $refreshed = $this->withHeaders([
            'DPoP' => $builder->proof(),
        ])->postJson('/api/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => 'jti-test-app',
            'refresh_token' => $initial->json('refresh_token'),
        ])->assertOk();

        $refreshedJti = $this->extractJti($refreshed->json('token'));
        $this->assertUuidV4($refreshedJti, 'rotated token');

        $this->assertNotSame(
            $initialJti,
            $refreshedJti,
            'refresh rotation must mint a fresh jti so the predecessor can be revoked independently (RFC 7009)'
        );
    }

    // --- POST /api/refresh-token (JSON body) --------------------------------------

    public function test_refresh_token_endpoint_mints_new_jti(): void
    {
        $plain = Str::random(64);

        UserRefreshToken::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->postJson('/api/refresh-token', [
            'refreshToken' => $plain,
        ]);

        $response->assertOk();

        $jti = $this->extractJti($response->json('token'));
        $this->assertUuidV4($jti, '/api/refresh-token');
    }

    public function test_two_successive_refresh_calls_mint_distinct_jti(): void
    {
        $plain = Str::random(64);

        UserRefreshToken::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ]);

        $first = $this->postJson('/api/refresh-token', [
            'refreshToken' => $plain,
        ])->assertOk();

        $jtiA = $this->extractJti($first->json('token'));

        // The refresh endpoint rotates the refresh-token, so the next call uses the new one.
        $plainB = $first->json('refresh_token');
        $this->assertNotNull($plainB, 'refresh-token endpoint must rotate the refresh token');

        $second = $this->postJson('/api/refresh-token', [
            'refreshToken' => $plainB,
        ])->assertOk();

        $jtiB = $this->extractJti($second->json('token'));

        $this->assertNotSame($jtiA, $jtiB, 'each refresh rotation must mint a fresh access-token jti');
    }
}
