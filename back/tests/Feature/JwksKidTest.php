<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature tests for LBDS-256 Task 1 (back): the /.well-known/jwks endpoint
 * exposes the configured kid, and access tokens issued via the live HTTP
 * surface carry that same kid in their header.
 *
 * Pins the integration contract between AuthService::getJwks() and
 * JwtService token builders. Both must agree on the configured kid; if they
 * drift, JWKS-based verification of issued tokens silently breaks.
 */
class JwksKidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // JWKS is cached for 24h; tests would otherwise see a stale JWKS if any
        // earlier test in the run populated the cache under a different kid.
        Cache::forget('jwks');
    }

    public function test_jwks_endpoint_exposes_configured_kid(): void
    {
        $expectedKid = (string) config('jwt.kid');

        // The route lives in routes/api.php; Laravel 12 prefixes api routes with /api,
        // so the JWKS endpoint is reachable at /api/.well-known/jwks.
        $response = $this->getJson('/api/.well-known/jwks');

        $response->assertOk()->assertJsonStructure([
            'keys' => [
                ['kty', 'use', 'alg', 'kid', 'n', 'e'],
            ],
        ]);

        $keys = $response->json('keys');
        $this->assertIsArray($keys);
        $this->assertNotEmpty($keys, 'JWKS must publish at least one key');

        $this->assertSame(
            $expectedKid,
            $keys[0]['kid'],
            'JWKS keys[0].kid must equal config(jwt.kid) so consumers can resolve by kid'
        );
    }

    public function test_access_token_header_kid_matches_jwks_kid(): void
    {
        $expectedKid = (string) config('jwt.kid');

        $user = User::factory()->create([
            'email' => 'kid@example.com',
            'password' => Hash::make('password123'),
        ]);

        $login = $this->postJson('/api/login', [
            'email' => 'kid@example.com',
            'password' => 'password123',
        ])->assertOk();

        $tokenString = $login->json('token');
        $this->assertIsString($tokenString);

        // Decode the token via the service so we read the header the same way
        // a resource server would. parseAndValidateToken must accept it (kid
        // matches what the service just signed with).
        $parsed = app(JwtService::class)->parseAndValidateToken($tokenString);
        $this->assertNotNull($parsed, 'freshly issued token must validate');

        $headerKid = $parsed->headers()->get('kid');
        $this->assertIsString($headerKid, 'header kid must be a string');
        $this->assertSame(
            $expectedKid,
            $headerKid,
            'access token header kid must equal config(jwt.kid) so JWKS lookup by kid succeeds'
        );

        // Cross-check: the kid in the token header must match the kid in the
        // JWKS payload. If they ever drift, /jwks-based verification breaks.
        $jwks = $this->getJson('/api/.well-known/jwks')->assertOk();
        $this->assertSame(
            $jwks->json('keys.0.kid'),
            $headerKid,
            'header kid and JWKS keys[0].kid must agree (single source of truth: config jwt.kid)'
        );
    }
}
