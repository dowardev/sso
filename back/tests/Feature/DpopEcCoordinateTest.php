<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\SsoClient;
use App\Models\User;
use App\Services\OAuth2Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\DpopProofBuilder;
use Tests\TestCase;

/**
 * Covers the ES256 DPoP proofs whose JWK omits the leading zero byte of a
 * coordinate.
 *
 * A P-256 coordinate is a fixed-length 32-byte value (RFC 7518 §3.4), but a
 * client that encodes it as a minimal big-endian integer drops the leading
 * 0x00 byte when the most significant byte of the coordinate is zero. That
 * happens for roughly 1 key in 256, and PHP's openssl_pkey_get_details does
 * exactly that, which made the DPoP test suite fail at random (~1 run in
 * 128) and made the server reject such proofs with a 401.
 *
 * The keypair below is a fixed fixture: its `y` coordinate is 31 bytes long
 * precisely because its leading 0x00 byte was dropped, so the case is
 * reproduced deterministically instead of by luck.
 */
class DpopEcCoordinateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * P-256 private key whose `y` coordinate loses its leading zero byte when
     * PHP reports the public point (the leading byte of `y` is 0x00).
     */
    private const FIXTURE_PRIVATE_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgSI/b0rDfsi1ldRHJ
gsfhMygeaHP0A3ZGn9mWthx3WAShRANCAASiN6kMkMSP2mYwWzN1JhYweJdE28fx
jacBRDEVtDOB/wCkEko/+3Eovi7BrPiVOX1GE8Yb1MkJCHVAp3iVRrEL
-----END PRIVATE KEY-----
PEM;

    /** The `x` coordinate, 32 bytes as RFC 7518 requires. */
    private const FIXTURE_X = 'ojepDJDEj9pmMFszdSYWMHiXRNvH8Y2nAUQxFbQzgf8';

    /** The `y` coordinate without its leading zero byte: 31 bytes. */
    private const FIXTURE_Y_SHORT = 'pBJKP_txKL4uwaz4lTl9RhPGG9TJCQh1QKd4lUaxCw';

    /** The same `y` coordinate, padded back to the fixed 32-byte length. */
    private const FIXTURE_Y_PADDED = 'AKQSSj_7cSi-LsGs-JU5fUYTxhvUyQkIdUCneJVGsQs';

    protected User $user;

    protected SsoClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'dpop-ec-coordinate@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->client = SsoClient::create([
            'name' => 'Test App',
            'client_id' => 'test-app',
            'redirect_url' => 'https://app.example.com/auth/callback',
            'allowed_origins' => ['https://app.example.com'],
            'grant_types' => ['authorization_code'],
            'active' => true,
        ]);
    }

    public function test_the_fixture_really_has_a_short_coordinate(): void
    {
        // Guards the fixture itself: if a future OpenSSL change made PHP
        // report the coordinate with its leading zero, the test below would
        // silently stop covering the case.
        $this->assertSame(31, strlen($this->base64UrlDecode(self::FIXTURE_Y_SHORT)));
        $this->assertSame(32, strlen($this->base64UrlDecode(self::FIXTURE_Y_PADDED)));
        $this->assertSame(
            self::FIXTURE_Y_PADDED,
            $this->base64UrlEncode("\x00".$this->base64UrlDecode(self::FIXTURE_Y_SHORT))
        );
    }

    public function test_the_builder_publishes_fixed_length_coordinates(): void
    {
        $builder = DpopProofBuilder::generateEc();

        foreach (['x', 'y'] as $coordinate) {
            $this->assertSame(
                32,
                strlen($this->base64UrlDecode($builder->jwkArray()[$coordinate])),
                "The {$coordinate} coordinate of the published JWK must be 32 bytes"
            );
        }
    }

    public function test_a_proof_signed_with_a_short_coordinate_jwk_is_accepted(): void
    {
        $builder = DpopProofBuilder::fromPrivateKeyPem(self::FIXTURE_PRIVATE_KEY);
        $shortJwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => self::FIXTURE_X,
            'y' => self::FIXTURE_Y_SHORT,
        ];

        $short = $builder->withPublicJwk($shortJwk);

        $refreshToken = $this->boundRefreshToken($short);
        $response = $this->refresh($refreshToken, $short->proof());

        $response->assertOk();
    }

    public function test_a_proof_signed_with_the_padded_coordinate_jwk_is_accepted(): void
    {
        // The same keypair published the conformant way: the short and the
        // padded JWK must both verify, so the fix cannot have broken the
        // regular path.
        $builder = DpopProofBuilder::fromPrivateKeyPem(self::FIXTURE_PRIVATE_KEY);
        $paddedJwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => self::FIXTURE_X,
            'y' => self::FIXTURE_Y_PADDED,
        ];

        $padded = $builder->withPublicJwk($paddedJwk);

        $refreshToken = $this->boundRefreshToken($padded);
        $response = $this->refresh($refreshToken, $padded->proof());

        $response->assertOk();
    }

    private function boundRefreshToken(DpopProofBuilder $builder): string
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $auth = app(OAuth2Service::class)->createAuthorizationCode($this->user, $this->client, [
            'redirect_uri' => 'https://app.example.com/auth/callback',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'test-app',
            'code' => $auth['code'],
            'redirect_uri' => 'https://app.example.com/auth/callback',
            'code_verifier' => $verifier,
            'dpop_public_key' => $builder->publicKey(),
        ]);

        $response->assertOk();

        return $response->json('refresh_token');
    }

    private function refresh(string $refreshToken, ?string $dpopHeader = null)
    {
        $request = $this->withHeaders($dpopHeader === null ? [] : ['DPoP' => $dpopHeader]);

        return $request->postJson('/api/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => 'test-app',
            'refresh_token' => $refreshToken,
        ]);
    }

    private function base64UrlDecode(string $data): string
    {
        $padded = str_pad(
            strtr($data, '-_', '+/'),
            strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4),
            '=',
            STR_PAD_RIGHT
        );

        return (string) base64_decode($padded, true);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
