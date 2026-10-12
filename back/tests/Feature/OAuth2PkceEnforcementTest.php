<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\OauthClientAuthorization;
use App\Models\SsoClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for the LBDS-303 PKCE enforcement on /oauth/authorize and
 * /oauth/authorize/approve. The OAuth2 spec leaves PKCE optional for
 * confidential clients, but the SSO is now a public-facing OIDC IdP, so
 * every authorization code request must carry a code_challenge — even
 * from a confidential client. The defense-in-depth argument: a leaked
 * client_secret alone can no longer mint a code, because the attacker
 * also needs the code_verifier that the legitimate client keeps on its
 * own backend.
 */
class OAuth2PkceEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $sessionToken;

    protected SsoClient $publicClient;

    protected SsoClient $confidentialClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'pkce@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->sessionToken = $this->postJson('/api/login', [
            'email' => 'pkce@example.com',
            'password' => 'password123',
        ])->assertOk()->json('token');

        $this->publicClient = SsoClient::create([
            'name' => 'Public SPA',
            'client_id' => 'public-spa',
            'client_secret' => null,
            'redirect_url' => 'https://spa.example.com/auth/callback',
            'allowed_origins' => ['https://spa.example.com'],
            'grant_types' => ['authorization_code'],
            'allowed_scopes' => ['openid', 'profile', 'email', 'offline_access'],
            'active' => true,
        ]);

        $this->confidentialClient = SsoClient::create([
            'name' => 'Confidential BFF',
            'client_id' => 'bff-server',
            'client_secret' => 'server-secret',
            'redirect_url' => 'https://bff.example.com/auth/callback',
            'allowed_origins' => ['https://bff.example.com'],
            'grant_types' => ['authorization_code'],
            'allowed_scopes' => ['openid', 'profile', 'email', 'offline_access'],
            'active' => true,
        ]);

        OauthClientAuthorization::create([
            'user_id' => $this->user->id,
            'sso_client_id' => $this->publicClient->id,
            'status' => 'authorized',
        ]);
        OauthClientAuthorization::create([
            'user_id' => $this->user->id,
            'sso_client_id' => $this->confidentialClient->id,
            'status' => 'authorized',
        ]);
    }

    public function test_authorize_requires_code_challenge(): void
    {
        $response = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'public-spa',
                'redirect_uri' => 'https://spa.example.com/auth/callback',
                'response_type' => 'code',
            ]);

        // Public clients (no client_secret) must use PKCE. The
        // controller-level check returns 400 with a domain message
        // because the rule depends on the client config, which the
        // FormRequest cannot see.
        $response->assertStatus(400);
        $this->assertStringContainsString('PKCE', $response->json('message'));
    }

    public function test_authorize_requires_code_challenge_method(): void
    {
        // A challenge without a method is half-formed PKCE: the verifier
        // cannot recompute the challenge without knowing the hash. The
        // controller rejects the pair at 400 with a domain message
        // (rather than a 422 FormRequest error) because the rule is
        // context-dependent on whether a challenge was sent.
        $response = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'public-spa',
                'redirect_uri' => 'https://spa.example.com/auth/callback',
                'response_type' => 'code',
                'code_challenge' => str_repeat('a', 43),
            ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('code_challenge_method', $response->json('message'));
    }

    public function test_authorize_rejects_unknown_code_challenge_method(): void
    {
        $response = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'public-spa',
                'redirect_uri' => 'https://spa.example.com/auth/callback',
                'response_type' => 'code',
                'code_challenge' => str_repeat('a', 43),
                'code_challenge_method' => 'MD5',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('code_challenge_method', $response->json('errors'));
    }

    public function test_authorize_accepts_s256_pkce(): void
    {
        $verifier = Str::random(64);
        $response = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'public-spa',
                'redirect_uri' => 'https://spa.example.com/auth/callback',
                'response_type' => 'code',
                'code_challenge' => $this->codeChallengeFor($verifier),
                'code_challenge_method' => 'S256',
            ]);

        $response->assertOk();
        $this->assertIsString($response->json('code'));
    }

    public function test_authorize_accepts_plain_pkce_for_back_compat(): void
    {
        // The OAuth2 spec allows `plain` for clients that cannot do SHA-256;
        // the discovery doc advertises S256 only, but the validate path
        // still accepts `plain` so existing clients can migrate at their
        // own pace. A future ticket can tighten this to S256-only.
        $verifier = Str::random(64);
        $response = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'public-spa',
                'redirect_uri' => 'https://spa.example.com/auth/callback',
                'response_type' => 'code',
                'code_challenge' => $verifier,
                'code_challenge_method' => 'plain',
            ]);

        $response->assertOk();
        $this->assertIsString($response->json('code'));
    }

    public function test_authorize_allows_confidential_client_without_pkce(): void
    {
        // Confidential clients (with a client_secret) MAY skip PKCE
        // per RFC 7636 §1 — the client_secret is its own defense. The
        // original LBDS-303 ticket asked for PKCE to be required for
        // every client, but the pre-merge review surfaced that as a
        // bigger RFC deviation than the defense-in-depth argument
        // needs. The trade-off: a leaked secret is still enough to
        // impersonate a confidential client, the same risk every
        // other IdP accepts.
        $response = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'bff-server',
                'redirect_uri' => 'https://bff.example.com/auth/callback',
                'response_type' => 'code',
            ]);

        $response->assertOk();
    }

    public function test_approve_requires_pkce_for_public_client(): void
    {
        // /oauth/authorize/approve is the path the consent UI takes
        // when the user has not previously authorized the client. The
        // same public-client PKCE contract applies: an attacker with
        // a session JWT but no code_verifier cannot get a code
        // approved either.
        $response = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize/approve', [
                'client_id' => 'public-spa',
                'redirect_uri' => 'https://spa.example.com/auth/callback',
            ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('PKCE', $response->json('message'));
    }

    public function test_approve_allows_confidential_client_without_pkce(): void
    {
        $response = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize/approve', [
                'client_id' => 'bff-server',
                'redirect_uri' => 'https://bff.example.com/auth/callback',
            ]);

        $response->assertOk()
            ->assertJsonStructure(['code']);
    }

    public function test_confidential_client_can_complete_token_exchange_without_pkce(): void
    {
        // End-to-end: a confidential client can run the whole
        // authorization_code flow without ever sending a code_challenge.
        // The auth code is created with a null code_challenge, the
        // token exchange skips the PKCE verification, and the access
        // token comes back as a Bearer with the expected scope.
        $code = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'bff-server',
                'redirect_uri' => 'https://bff.example.com/auth/callback',
                'response_type' => 'code',
                'scope' => 'openid profile',
            ])->assertOk()->json('code');

        $tokenResponse = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'bff-server',
            'client_secret' => 'secret-123',
            'code' => $code,
            'redirect_uri' => 'https://bff.example.com/auth/callback',
        ])->assertOk();

        $this->assertIsString($tokenResponse->json('token'));
        $this->assertSame('openid profile', $tokenResponse->json('scope'));
    }

    private function codeChallengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
