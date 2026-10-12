<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\OAuthAuthCode;
use App\Models\OauthClientAuthorization;
use App\Models\SsoClient;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for the OAuth2 scope plumbing added by LBDS-303 step 1.
 *
 * The scope string the RP sends at /oauth/authorize now flows through to the
 * auth code, then to the access token's `scope` claim, and back out in the
 * token response. These tests pin every link of that chain so a regression
 * that drops the scope at any hop surfaces as a focused failure here, not as
 * a confused /oauth/userinfo or id_token consumer later.
 */
class OAuth2ScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $sessionToken;

    protected SsoClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'scope-test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->sessionToken = $this->postJson('/api/login', [
            'email' => 'scope-test@example.com',
            'password' => 'password123',
        ])->assertOk()->json('token');

        $this->client = SsoClient::create([
            'name' => 'Scoped App',
            'client_id' => 'scoped-app',
            'client_secret' => 'secret-123',
            'redirect_url' => 'https://app.example.com/auth/callback',
            'allowed_origins' => ['https://app.example.com'],
            'grant_types' => ['authorization_code'],
            'allowed_scopes' => ['openid', 'profile', 'email', 'offline_access'],
            'active' => true,
        ]);

        // Pre-authorize the user so /oauth/authorize goes straight to
        // issuing a code (matches the happy-path integration we want to
        // exercise: the scope survives every hop, authorize → auth code →
        // token).
        OauthClientAuthorization::create([
            'user_id' => $this->user->id,
            'sso_client_id' => $this->client->id,
            'status' => 'authorized',
        ]);
    }

    public function test_authorize_defaults_to_client_full_allowed_scopes_when_none_sent(): void
    {
        $response = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'scoped-app',
                'redirect_uri' => 'https://app.example.com/auth/callback',
                'code_challenge' => $this->codeChallengeFor('verifier'),
                'code_challenge_method' => 'S256',
            ]);

        $response->assertOk();

        $code = $response->json('code');
        $this->assertIsString($code);

        // The default must be every scope the client is allowed, joined
        // space-separated and persisted on the auth code. Without this, a
        // client that never sent `scope=` would get an empty scope on
        // every token, breaking the OIDC userinfo claim groups.
        $authCode = OAuthAuthCode::where('code', $code)->firstOrFail();
        $this->assertSame('openid profile email offline_access', $authCode->scope);
    }

    public function test_authorize_accepts_a_subset_of_allowed_scopes(): void
    {
        $response = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'scoped-app',
                'redirect_uri' => 'https://app.example.com/auth/callback',
                'scope' => 'openid email',
                'code_challenge' => $this->codeChallengeFor('verifier-2'),
                'code_challenge_method' => 'S256',
            ]);

        $response->assertOk();

        $authCode = OAuthAuthCode::where('code', $response->json('code'))->firstOrFail();
        $this->assertSame('openid email', $authCode->scope);
    }

    public function test_authorize_rejects_scope_with_token_outside_allowed_scopes(): void
    {
        $response = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'scoped-app',
                'redirect_uri' => 'https://app.example.com/auth/callback',
                'scope' => 'openid admin',
                'code_challenge' => $this->codeChallengeFor('verifier-3'),
                'code_challenge_method' => 'S256',
            ]);

        // Fail closed: an unknown scope token must not produce a code,
        // regardless of which subset it is part of. The error names the
        // offending token so the developer can fix their config.
        $response->assertStatus(400);
        $this->assertStringContainsString('admin', $response->json('message'));
    }

    public function test_token_response_carries_scope_and_access_token_has_scope_claim(): void
    {
        $verifier = Str::random(64);
        $code = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'scoped-app',
                'redirect_uri' => 'https://app.example.com/auth/callback',
                'scope' => 'openid profile email',
                'code_challenge' => $this->codeChallengeFor($verifier),
                'code_challenge_method' => 'S256',
            ])->assertOk()->json('code');

        $tokenResponse = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'scoped-app',
            'client_secret' => 'secret-123',
            'code' => $code,
            'redirect_uri' => 'https://app.example.com/auth/callback',
            'code_verifier' => $verifier,
        ])->assertOk();

        // OAuth2 RFC 6749 §5.1: the token response includes `scope` when
        // it differs from the one requested (or always, for safety). A
        // missing `scope` here would tell the OIDC client that no scopes
        // were granted, which is a contract break.
        $tokenResponse->assertJsonPath('scope', 'openid profile email');

        $accessToken = $tokenResponse->json('token');
        $this->assertIsString($accessToken);

        // Cross-check that the JWT actually carries the same `scope` claim,
        // not just the JSON response. userinfo / id_token consumers read
        // it from the token itself.
        $parsed = app(JwtService::class)->parseAndValidateToken($accessToken);
        $this->assertNotNull($parsed, 'freshly issued access token must validate');
        $this->assertSame(
            'openid profile email',
            $parsed->claims()->get('scope'),
            'access token JWT must carry the granted scope string so /userinfo can read it'
        );
    }

    public function test_client_with_null_allowed_scopes_falls_back_to_oidc_core_set(): void
    {
        // Pre-LBDS-303 clients have a null allowed_scopes. They must keep
        // working with the OIDC core + offline_access fallback, otherwise
        // every existing client would need a config migration at deploy.
        $legacy = SsoClient::create([
            'name' => 'Legacy App',
            'client_id' => 'legacy-app',
            'client_secret' => 'legacy-secret',
            'redirect_url' => 'https://legacy.example.com/auth/callback',
            'allowed_origins' => ['https://legacy.example.com'],
            'grant_types' => ['authorization_code'],
            'allowed_scopes' => null,
            'active' => true,
        ]);

        OauthClientAuthorization::create([
            'user_id' => $this->user->id,
            'sso_client_id' => $legacy->id,
            'status' => 'authorized',
        ]);

        $code = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'legacy-app',
                'redirect_uri' => 'https://legacy.example.com/auth/callback',
                'code_challenge' => $this->codeChallengeFor('legacy-verifier'),
                'code_challenge_method' => 'S256',
            ])->assertOk()->json('code');

        $authCode = OAuthAuthCode::where('code', $code)->firstOrFail();
        $this->assertSame('openid profile email offline_access', $authCode->scope);
    }

    private function codeChallengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
