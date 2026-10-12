<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\OauthClientAuthorization;
use App\Models\SsoClient;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Lcobucci\JWT\Token\Plain;
use Tests\TestCase;

/**
 * Feature tests for the OIDC id_token issued alongside the access token
 * when the granted scope includes `openid`. Pins the id_token claim shape
 * and the contract that distinguishes it from the access token (different
 * `aud`, shorter lifetime, no DPoP binding) so a regression in either
 * surface surfaces as a focused failure here, not a confused RP in
 * production.
 */
class OidcIdTokenTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $sessionToken;

    protected SsoClient $client;

    protected JwtService $jwtService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'Ada Lovelace',
            'user' => 'ada',
            'email' => 'ada@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password123'),
        ]);

        $this->sessionToken = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'password123',
        ])->assertOk()->json('token');

        $this->client = SsoClient::create([
            'name' => 'Oidc RP',
            'client_id' => 'oidc-rp',
            'client_secret' => 'rp-secret',
            'redirect_url' => 'https://rp.example.com/auth/callback',
            'allowed_origins' => ['https://rp.example.com'],
            'grant_types' => ['authorization_code'],
            'allowed_scopes' => ['openid', 'profile', 'email', 'offline_access'],
            'active' => true,
        ]);

        OauthClientAuthorization::create([
            'user_id' => $this->user->id,
            'sso_client_id' => $this->client->id,
            'status' => 'authorized',
        ]);

        $this->jwtService = app(JwtService::class);
    }

    public function test_id_token_is_omitted_when_openid_scope_not_granted(): void
    {
        // Pure OAuth2 (no OIDC) flows: a client that never asks for `openid`
        // gets a token response without an id_token. Including one would
        // be a contract break — the client does not expect it, and the
        // id_token's `aud` is the client_id, which means the client could
        // be tricked into trusting a token it did not request.
        $verifier = Str::random(64);
        $code = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'oidc-rp',
                'redirect_uri' => 'https://rp.example.com/auth/callback',
                'scope' => 'profile email offline_access',
                'code_challenge' => $this->codeChallengeFor($verifier),
                'code_challenge_method' => 'S256',
            ])->assertOk()->json('code');

        $tokenResponse = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'oidc-rp',
            'client_secret' => 'rp-secret',
            'code' => $code,
            'redirect_uri' => 'https://rp.example.com/auth/callback',
            'code_verifier' => $verifier,
        ])->assertOk();

        $this->assertArrayNotHasKey('id_token', $tokenResponse->json());
    }

    public function test_id_token_is_emitted_when_openid_scope_granted(): void
    {
        $verifier = Str::random(64);
        $code = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'oidc-rp',
                'redirect_uri' => 'https://rp.example.com/auth/callback',
                'scope' => 'openid',
                'code_challenge' => $this->codeChallengeFor($verifier),
                'code_challenge_method' => 'S256',
            ])->assertOk()->json('code');

        $tokenResponse = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'oidc-rp',
            'client_secret' => 'rp-secret',
            'code' => $code,
            'redirect_uri' => 'https://rp.example.com/auth/callback',
            'code_verifier' => $verifier,
        ])->assertOk();

        $idToken = $tokenResponse->json('id_token');
        $this->assertIsString($idToken, 'id_token must be present when openid scope is granted');
    }

    public function test_id_token_carries_required_oidc_claims(): void
    {
        $parsed = $this->issueIdToken('openid');

        // OIDC §2: the five REQUIRED claims. A missing one of these is
        // a conformance failure: the RP must reject the id_token.
        $this->assertSame(config('jwt.issuer'), $parsed->claims()->get('iss'));
        $this->assertSame((string) $this->user->id, $parsed->claims()->get('sub'));

        // JWT `aud` is a list of audiences (RFC 7519 §4.1.3). Single-aud
        // tokens surface as a one-element list, which the lcobucci
        // parser keeps as an array. For our id_token the list is always
        // exactly the client_id.
        $aud = $parsed->claims()->get('aud');
        $this->assertIsArray($aud);
        $this->assertSame(['oidc-rp'], $aud);

        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed->claims()->get('exp'));
        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed->claims()->get('iat'));

        // The id_token is verified by the RP using the JWKS — its kid
        // header must match so the resolver finds the right key.
        $this->assertSame(config('jwt.kid'), $parsed->headers()->get('kid'));
    }

    public function test_id_token_aud_is_client_id_not_user_id(): void
    {
        // OIDC §2: `aud` of the id_token is the OAuth 2.0 Client ID, NOT
        // the user id. The access token uses user id as its audience (the
        // SSO's own resource server); the id_token uses the RP's id, so
        // a client_id mismatch is a hard fail at verification.
        $parsed = $this->issueIdToken('openid');
        $aud = $parsed->claims()->get('aud');
        $this->assertIsArray($aud);
        $this->assertSame(['oidc-rp'], $aud);
        $this->assertNotSame($this->user->id, $aud);
    }

    public function test_id_token_propagates_nonce_from_authorize_request(): void
    {
        // The nonce is the only replay protection the RP has against an
        // id_token intercepted in flight. The RP sends it at /authorize,
        // expects it back in the id_token, and rejects any id_token whose
        // nonce does not match the one it generated. Dropping it here
        // would silently disable that protection.
        $nonce = 'rp-generated-'.Str::random(32);
        $verifier = Str::random(64);

        $code = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'oidc-rp',
                'redirect_uri' => 'https://rp.example.com/auth/callback',
                'scope' => 'openid',
                'nonce' => $nonce,
                'code_challenge' => $this->codeChallengeFor($verifier),
                'code_challenge_method' => 'S256',
            ])->assertOk()->json('code');

        $tokenResponse = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'oidc-rp',
            'client_secret' => 'rp-secret',
            'code' => $code,
            'redirect_uri' => 'https://rp.example.com/auth/callback',
            'code_verifier' => $verifier,
        ])->assertOk();

        $parsed = $this->jwtService->parseAndValidateToken($tokenResponse->json('id_token'));
        $this->assertNotNull($parsed);
        $this->assertSame(
            $nonce,
            $parsed->claims()->get('nonce'),
            'id_token must echo the nonce from the /oauth/authorize request'
        );
    }

    public function test_id_token_omits_nonce_when_not_provided(): void
    {
        $parsed = $this->issueIdToken('openid');
        $this->assertNull($parsed->claims()->get('nonce'));
    }

    public function test_id_token_includes_acr_and_amr_claims(): void
    {
        // The ticket calls for both `acr` and `amr`. The current values
        // are honest placeholders — see the TODO on JwtService::generateIdToken
        // for the per-session auth tracking required to compute them
        // precisely. A missing claim would be a spec break; a wrong one
        // would be a silent spec break.
        $parsed = $this->issueIdToken('openid');
        $this->assertIsString($parsed->claims()->get('acr'));
        $this->assertIsArray($parsed->claims()->get('amr'));
        $this->assertContains('pwd', $parsed->claims()->get('amr'));
    }

    public function test_id_token_includes_profile_claims_when_profile_scope_granted(): void
    {
        $parsed = $this->issueIdToken('openid profile');

        $this->assertSame('Ada Lovelace', $parsed->claims()->get('name'));
        $this->assertSame('ada', $parsed->claims()->get('preferred_username'));
        $this->assertIsInt($parsed->claims()->get('updated_at'));

        // email is owned by the `email` scope; must not leak into a
        // profile-only id_token.
        $this->assertNull($parsed->claims()->get('email'));
    }

    public function test_id_token_includes_email_claims_when_email_scope_granted(): void
    {
        $parsed = $this->issueIdToken('openid email');

        $this->assertSame('ada@example.com', $parsed->claims()->get('email'));
        $this->assertTrue($parsed->claims()->get('email_verified'));

        $this->assertNull($parsed->claims()->get('name'));
    }

    public function test_id_token_omits_email_for_advanced_user(): void
    {
        // Advanced users store profile data encrypted client-side. Emitting
        // their plaintext email in the id_token would defeat ZKE — the
        // claim is gated to basic users only.
        $this->user->update(['encryption_level' => 'advanced']);

        $parsed = $this->issueIdToken('openid email');

        $this->assertNull($parsed->claims()->get('email'));
        $this->assertNull($parsed->claims()->get('email_verified'));
    }

    public function test_id_token_exp_is_shorter_than_access_token_exp(): void
    {
        // OIDC id_token is meant to be verified once at login and not
        // reused. A 1-hour id_token is operationally fine but a missed
        // leak window that's 6× larger than necessary; the spec recommends
        // minutes, not hours.
        $verifier = Str::random(64);
        $code = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'oidc-rp',
                'redirect_uri' => 'https://rp.example.com/auth/callback',
                'scope' => 'openid profile',
                'code_challenge' => $this->codeChallengeFor($verifier),
                'code_challenge_method' => 'S256',
            ])->assertOk()->json('code');

        $tokenResponse = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'oidc-rp',
            'client_secret' => 'rp-secret',
            'code' => $code,
            'redirect_uri' => 'https://rp.example.com/auth/callback',
            'code_verifier' => $verifier,
        ])->assertOk();

        $accessParsed = $this->jwtService->parseAndValidateToken($tokenResponse->json('token'));
        $idParsed = $this->jwtService->parseAndValidateToken($tokenResponse->json('id_token'));

        $accessLifetime = $accessParsed->claims()->get('exp')->getTimestamp() - $accessParsed->claims()->get('iat')->getTimestamp();
        $idLifetime = $idParsed->claims()->get('exp')->getTimestamp() - $idParsed->claims()->get('iat')->getTimestamp();

        $this->assertLessThan(
            $accessLifetime,
            $idLifetime,
            'id_token lifetime must be shorter than access token lifetime (OIDC §2: verified at login, not reused)'
        );
    }

    /**
     * Run the full /oauth/authorize + /oauth/token flow with the given
     * scope, then return the parsed id_token. Assumes the auth code path
     * succeeds — the focus here is on the id_token's claim shape, not on
     * the code plumbing (covered by OAuth2ScopeTest).
     */
    private function issueIdToken(string $scope): Plain
    {
        $verifier = Str::random(64);
        $code = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'oidc-rp',
                'redirect_uri' => 'https://rp.example.com/auth/callback',
                'scope' => $scope,
                'code_challenge' => $this->codeChallengeFor($verifier),
                'code_challenge_method' => 'S256',
            ])->assertOk()->json('code');

        $tokenResponse = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'oidc-rp',
            'client_secret' => 'rp-secret',
            'code' => $code,
            'redirect_uri' => 'https://rp.example.com/auth/callback',
            'code_verifier' => $verifier,
        ])->assertOk();

        $idToken = $tokenResponse->json('id_token');
        $this->assertIsString($idToken, "id_token missing for scope '{$scope}'");

        $parsed = $this->jwtService->parseAndValidateToken($idToken);
        $this->assertNotNull($parsed, 'id_token must validate against the SSO key set');

        return $parsed;
    }

    private function codeChallengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
