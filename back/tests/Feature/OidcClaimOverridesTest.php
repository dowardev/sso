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
 * Feature tests for the per-client claim overrides added by LBDS-303
 * step 5. The SsoClient.claim_overrides column lets an RP declare
 * extra claims it wants surfaced in both the id_token and /oauth/userinfo
 * response, either as literal values or as `user.<field>` paths into the
 * authenticated user.
 *
 * Tests pin the contract from both sides (id_token and userinfo) so a
 * regression that drops the override, misapplies the user.<path> syntax,
 * or accidentally lets an override shadow a reserved OIDC claim surfaces
 * here, not as a confused RP in production.
 */
class OidcClaimOverridesTest extends TestCase
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
            'name' => 'Override RP',
            'client_id' => 'override-rp',
            'client_secret' => 'override-secret',
            'redirect_url' => 'https://override.example.com/auth/callback',
            'allowed_origins' => ['https://override.example.com'],
            'grant_types' => ['authorization_code'],
            'allowed_scopes' => ['openid', 'profile', 'email', 'offline_access'],
            'claim_overrides' => [
                'extra_claims' => [
                    'tenant_id' => 'acme-corp',
                    'subscription_tier' => 'premium',
                    'login_name' => 'user.user',
                ],
            ],
            'active' => true,
        ]);

        OauthClientAuthorization::create([
            'user_id' => $this->user->id,
            'sso_client_id' => $this->client->id,
            'status' => 'authorized',
        ]);

        $this->jwtService = app(JwtService::class);
    }

    public function test_id_token_includes_literal_override_claims(): void
    {
        $parsed = $this->issueIdToken('openid profile');

        $this->assertSame('acme-corp', $parsed->claims()->get('tenant_id'));
        $this->assertSame('premium', $parsed->claims()->get('subscription_tier'));
    }

    public function test_id_token_resolves_user_path_overrides(): void
    {
        $parsed = $this->issueIdToken('openid profile');

        // `user.user` resolves to the user's `user` attribute (the SSO's
        // internal username column, separate from `name`). The override
        // lets the RP surface it as a claim called `login_name` without
        // any code change in the SSO's user model.
        $this->assertSame('ada', $parsed->claims()->get('login_name'));
    }

    public function test_id_token_does_not_shadow_reserved_oidc_claims(): void
    {
        // An override named `sub` or `iss` would be a spec break: those
        // are REQUIRED by OIDC §2 and have a defined value. The resolver
        // must skip them so a misconfigured RP cannot poison its own
        // id_token.
        $this->client->update([
            'claim_overrides' => [
                'extra_claims' => [
                    'sub' => 'attacker-controlled',
                    'iss' => 'https://attacker.example.com',
                    'safe_extra' => 'kept',
                ],
            ],
        ]);

        $parsed = $this->issueIdToken('openid');

        $this->assertSame((string) $this->user->id, $parsed->claims()->get('sub'));
        $this->assertSame(config('jwt.issuer'), $parsed->claims()->get('iss'));
        $this->assertSame('kept', $parsed->claims()->get('safe_extra'));
    }

    public function test_id_token_silently_skips_unknown_user_paths(): void
    {
        // `user.does_not_exist` is an unknown attribute; the resolver
        // returns null and the claim is dropped, instead of leaking
        // the raw path string into the id_token.
        $this->client->update([
            'claim_overrides' => [
                'extra_claims' => [
                    'bogus' => 'user.does_not_exist',
                    'kept' => 'user.email',
                ],
            ],
        ]);

        $parsed = $this->issueIdToken('openid email');

        $this->assertNull($parsed->claims()->get('bogus'));
        $this->assertSame('ada@example.com', $parsed->claims()->get('kept'));
    }

    public function test_userinfo_applies_overrides_on_top_of_scope_claims(): void
    {
        $accessToken = $this->issueAccessToken('openid profile email');

        $response = $this->withToken($accessToken)
            ->getJson('/api/oauth/userinfo')
            ->assertOk();

        // Standard claims from scopes must still be present.
        $response->assertJsonPath('sub', (string) $this->user->id);
        $response->assertJsonPath('name', 'Ada Lovelace');
        $response->assertJsonPath('email', 'ada@example.com');

        // Per-client overrides are added on top.
        $response->assertJsonPath('tenant_id', 'acme-corp');
        $response->assertJsonPath('subscription_tier', 'premium');
        $response->assertJsonPath('login_name', 'ada');
    }

    public function test_userinfo_without_overrides_returns_standard_claims_only(): void
    {
        // Clients predating LBDS-303 have claim_overrides = null and
        // must keep the pre-LBDS-303 contract: only the scope-driven
        // claim groups, nothing else.
        $this->client->update(['claim_overrides' => null]);

        $accessToken = $this->issueAccessToken('openid profile email');
        $response = $this->withToken($accessToken)
            ->getJson('/api/oauth/userinfo')
            ->assertOk();

        $this->assertArrayNotHasKey('tenant_id', $response->json());
        $this->assertArrayNotHasKey('subscription_tier', $response->json());
    }

    public function test_access_token_carries_client_id_for_userinfo_resolution(): void
    {
        // The userinfo endpoint must be able to resolve the requesting
        // client from the access token alone, without re-reading the
        // session. The `client_id` claim is the bridge that makes that
        // possible — and the CORS middleware's existing client_id
        // resolver can also reuse it, so this is one claim with two
        // consumers.
        $parsed = $this->parseAccessToken();
        $this->assertSame('override-rp', $parsed->claims()->get('client_id'));
    }

    private function issueIdToken(string $scope): Plain
    {
        $verifier = Str::random(64);
        $code = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'override-rp',
                'redirect_uri' => 'https://override.example.com/auth/callback',
                'scope' => $scope,
                'code_challenge' => $this->codeChallengeFor($verifier),
                'code_challenge_method' => 'S256',
            ])->assertOk()->json('code');

        $tokenResponse = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'override-rp',
            'client_secret' => 'override-secret',
            'code' => $code,
            'redirect_uri' => 'https://override.example.com/auth/callback',
            'code_verifier' => $verifier,
        ])->assertOk();

        $parsed = $this->jwtService->parseAndValidateToken($tokenResponse->json('id_token'));
        $this->assertNotNull($parsed);

        return $parsed;
    }

    private function issueAccessToken(string $scope): string
    {
        $verifier = Str::random(64);
        $code = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'override-rp',
                'redirect_uri' => 'https://override.example.com/auth/callback',
                'scope' => $scope,
                'code_challenge' => $this->codeChallengeFor($verifier),
                'code_challenge_method' => 'S256',
            ])->assertOk()->json('code');

        return $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'override-rp',
            'client_secret' => 'override-secret',
            'code' => $code,
            'redirect_uri' => 'https://override.example.com/auth/callback',
            'code_verifier' => $verifier,
        ])->assertOk()->json('token');
    }

    private function parseAccessToken(): Plain
    {
        $verifier = Str::random(64);
        $code = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'override-rp',
                'redirect_uri' => 'https://override.example.com/auth/callback',
                'scope' => 'openid',
                'code_challenge' => $this->codeChallengeFor($verifier),
                'code_challenge_method' => 'S256',
            ])->assertOk()->json('code');

        $tokenResponse = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'override-rp',
            'client_secret' => 'override-secret',
            'code' => $code,
            'redirect_uri' => 'https://override.example.com/auth/callback',
            'code_verifier' => $verifier,
        ])->assertOk();

        $parsed = $this->jwtService->parseAndValidateToken($tokenResponse->json('token'));
        $this->assertNotNull($parsed);

        return $parsed;
    }

    private function codeChallengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
