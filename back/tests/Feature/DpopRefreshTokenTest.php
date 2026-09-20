<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\SsoClient;
use App\Models\User;
use App\Models\UserRefreshToken;
use App\Services\JwtService;
use App\Services\OAuth2Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\DpopProofBuilder;
use Tests\TestCase;

class DpopRefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected SsoClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'dpop@example.com',
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

    public function test_refresh_without_dpop_header_is_rejected(): void
    {
        $builder = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($builder);

        $this->refresh($refreshToken)->assertUnauthorized();
    }

    public function test_proof_signed_by_different_key_is_rejected(): void
    {
        $builder = DpopProofBuilder::generate();
        $other = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($builder);

        $this->refresh($refreshToken, $other->proof())->assertUnauthorized();
    }

    public function test_proof_with_stale_iat_is_rejected(): void
    {
        $builder = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($builder);

        $proof = $builder->proof(['iat' => time() - 600]);

        $this->refresh($refreshToken, $proof)->assertUnauthorized();
    }

    public function test_replayed_jti_is_rejected_across_bound_tokens(): void
    {
        $builder = DpopProofBuilder::generate();
        $first = $this->boundRefreshToken($builder);
        $second = $this->boundRefreshToken($builder);

        $proof = $builder->proof(['jti' => 'fixed-replay-jti']);

        $this->refresh($first, $proof)->assertOk();
        $this->refresh($second, $proof)->assertUnauthorized();
    }

    public function test_unbound_refresh_token_is_rejected(): void
    {
        $plain = Str::random(64);

        UserRefreshToken::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ]);

        $builder = DpopProofBuilder::generate();

        $this->refresh($plain, $builder->proof())->assertUnauthorized();
    }

    public function test_valid_proof_rotates_and_preserves_binding(): void
    {
        $builder = DpopProofBuilder::generate();
        $expectedJkt = $builder->jkt();
        $refreshToken = $this->boundRefreshToken($builder);

        $response = $this->refresh($refreshToken, $builder->proof());

        $response->assertOk()->assertJsonStructure(['token', 'token_type', 'expires_in', 'refresh_token']);

        $newRefreshToken = $response->json('refresh_token');
        $this->assertNotSame($refreshToken, $newRefreshToken);

        $old = UserRefreshToken::where('token_hash', hash('sha256', $refreshToken))->first();
        $this->assertNotNull($old->revoked_at);

        $new = UserRefreshToken::where('token_hash', hash('sha256', $newRefreshToken))->first();
        $this->assertNotNull($new);
        $this->assertSame($expectedJkt, $new->dpop_jkt);

        $parsed = app(JwtService::class)->parseAndValidateToken($response->json('token'));
        $this->assertNotNull($parsed);
        $this->assertSame($expectedJkt, $parsed->claims()->get('cnf')['jkt'] ?? null);
    }

    // Documents the deliberate legacy behavior: generateToken() accepts a DPoP
    // public key but does not emit `cnf`. The DPoP-activation ticket will flip this.
    public function test_access_token_from_login_style_generation_has_no_cnf_claim(): void
    {
        $builder = DpopProofBuilder::generate();

        $result = app(JwtService::class)->generateToken($this->user, $builder->publicKey());

        $parsed = app(JwtService::class)->parseAndValidateToken($result['token']);

        $this->assertNotNull($parsed);
        $this->assertNull($parsed->claims()->get('cnf'));
        $this->assertSame('DPoP', $result['token_type']);
    }

    // --- F1: /api/refresh-token must enforce a binding's proof too ------------------

    public function test_refresh_token_endpoint_requires_proof_for_bound_token(): void
    {
        $builder = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($builder);

        $this->postJson('/api/refresh-token', [
            'refreshToken' => $refreshToken,
        ])->assertUnauthorized();
    }

    public function test_refresh_token_endpoint_accepts_bound_token_with_proof(): void
    {
        $builder = DpopProofBuilder::generate();
        $expectedJkt = $builder->jkt();
        $refreshToken = $this->boundRefreshToken($builder);

        $response = $this->withHeaders(['DPoP' => $builder->proof(['htu' => 'http://localhost/api/refresh-token'])])->postJson('/api/refresh-token', [
            'refreshToken' => $refreshToken,
        ]);

        $response->assertOk();

        $newRefreshToken = $response->json('refresh_token');
        $this->assertNotSame($refreshToken, $newRefreshToken);

        $new = UserRefreshToken::where('token_hash', hash('sha256', $newRefreshToken))->first();
        $this->assertNotNull($new);
        $this->assertSame($expectedJkt, $new->dpop_jkt);

        $parsed = app(JwtService::class)->parseAndValidateToken($response->json('token'));
        $this->assertNotNull($parsed);
        $this->assertSame($expectedJkt, $parsed->claims()->get('cnf')['jkt'] ?? null);
    }

    public function test_refresh_token_endpoint_accepts_unbound_token_without_proof(): void
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

        $parsed = app(JwtService::class)->parseAndValidateToken($response->json('token'));
        $this->assertNotNull($parsed);
        $this->assertNull($parsed->claims()->get('cnf'));
    }

    // --- F1b: the login/cookie flow stays unbound -----------------------------------

    public function test_login_issued_refresh_token_has_no_dpop_binding(): void
    {
        $builder = DpopProofBuilder::generate();

        $response = $this->postJson('/api/login', [
            'email' => 'dpop@example.com',
            'password' => 'password123',
            'dpop_public_key' => $builder->publicKey(),
        ]);

        $response->assertOk();

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'refresh_token');
        $this->assertNotNull($cookie);

        $row = UserRefreshToken::where('token_hash', hash('sha256', $cookie->getValue()))->first();
        $this->assertNotNull($row);
        $this->assertNull($row->dpop_jkt);
    }

    // --- F5: proof validation boundaries --------------------------------------------

    public function test_proof_with_valid_thumbprint_but_invalid_signature_is_rejected(): void
    {
        $builder = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($builder);

        // Correct header JWK/thumbprint; the signature segment no longer verifies.
        $proof = $builder->proof(['tamperSignature' => true]);

        $this->refresh($refreshToken, $proof)->assertUnauthorized();
    }

    public function test_proof_with_wrong_htm_is_rejected(): void
    {
        $builder = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($builder);

        $this->refresh($refreshToken, $builder->proof(['htm' => 'GET']))->assertUnauthorized();
    }

    public function test_proof_with_wrong_htu_is_rejected(): void
    {
        $builder = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($builder);

        $this->refresh($refreshToken, $builder->proof(['htu' => 'http://localhost/api/some-other-path']))->assertUnauthorized();
    }

    public function test_proof_with_ath_but_no_bearer_token_is_rejected(): void
    {
        $builder = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($builder);

        $proof = $builder->proof(['ath' => DpopProofBuilder::ath('an-access-token-never-sent')]);

        $this->refresh($refreshToken, $proof)->assertUnauthorized();
    }

    // --- F3: theft detection still runs before DPoP enforcement on /oauth/token -----

    // Regression guard for the audit ordering fix: `refreshAccessToken()` must
    // run token validation / theft-chain revocation BEFORE the DPoP proof
    // enforcement. The replay carries NO proof: with the checks reversed the
    // endpoint would return before the theft logic, leaving the successor
    // alive, so the successor-row assertion is what makes this discriminate.
    public function test_revoked_bound_token_replay_without_proof_revokes_chain(): void
    {
        $builder = DpopProofBuilder::generate();
        $originalToken = $this->boundRefreshToken($builder);

        $first = $this->refresh($originalToken, $builder->proof());
        $first->assertOk();
        $rotatedPlain = $first->json('refresh_token');

        // Simulate a theft replay of the old token after the grace window.
        UserRefreshToken::where('token_hash', hash('sha256', $originalToken))
            ->update(['revoked_at' => now()->subSeconds(31)]);

        $this->refresh($originalToken)->assertUnauthorized();

        // Entire chain revoked — the rotated successor must also be dead.
        $successor = UserRefreshToken::where('token_hash', hash('sha256', $rotatedPlain))->first();
        $this->assertNotNull($successor);
        $this->assertNotNull($successor->revoked_at);
    }

    // A bound refresh token must not be refreshable through the cookie-only
    // SSO web flow (which has no DPoP header) — F1 counterpart of the
    // /oauth/token guard above.
    public function test_bound_token_via_cookie_without_proof_is_rejected(): void
    {
        $builder = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($builder);

        $this->call('POST', '/api/refresh-token', [], [
            'refresh_token' => $refreshToken,
        ])->assertUnauthorized();
    }

    // `ath` must be computed over exactly the bearer access token sent in the
    // Authorization header. The bearer value itself does NOT need to be a valid
    // JWT — the verifier only hashes it — so an arbitrary string still
    // exercises the full proof-vs-header comparison.
    public function test_refresh_with_ath_and_matching_bearer_succeeds(): void
    {
        $builder = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($builder);

        $bearer = 'arbitrary-bearer-value-not-a-real-jwt';
        $proof = $builder->proof(['ath' => DpopProofBuilder::ath($bearer)]);

        $response = $this->withHeaders([
            'DPoP' => $proof,
            'Authorization' => 'Bearer '.$bearer,
        ])->postJson('/api/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => 'test-app',
            'refresh_token' => $refreshToken,
        ]);

        $response->assertOk();
        $this->assertNotSame($refreshToken, $response->json('refresh_token'));
    }

    // --- Helpers -------------------------------------------------------------------

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

    private function refresh(string $refreshToken, ?string $dpopHeader = null): TestResponse
    {
        $request = $this->withHeaders($dpopHeader === null ? [] : ['DPoP' => $dpopHeader]);

        return $request->postJson('/api/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => 'test-app',
            'refresh_token' => $refreshToken,
        ]);
    }
}
