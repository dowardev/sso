<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the OIDC Core 1.0 §5.3 userinfo endpoint exposed at
 * GET /oauth/userinfo.
 *
 * Pins the claim shape returned for each scope group so a regression that
 * leaks a wrong field (e.g. an internal column, a wrong verified flag, or a
 * missing scope filter) is caught here, not by a confused RP in production.
 */
class OidcUserinfoTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

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

        $this->jwtService = app(JwtService::class);
    }

    public function test_userinfo_returns_401_without_bearer_token(): void
    {
        $this->getJson('/api/oauth/userinfo')
            ->assertStatus(401);
    }

    public function test_userinfo_returns_401_with_invalid_bearer_token(): void
    {
        $this->withToken('not-a-valid-jwt')
            ->getJson('/api/oauth/userinfo')
            ->assertStatus(401);
    }

    public function test_userinfo_returns_sub_only_for_openid_scope(): void
    {
        $accessToken = $this->issueAccessToken('openid');

        $response = $this->withToken($accessToken)
            ->getJson('/api/oauth/userinfo')
            ->assertOk();

        // OIDC §5.4: sub is REQUIRED in every userinfo response, regardless
        // of granted scopes. It MUST be the locally-unique, never-reassigned
        // subject identifier — for this SSO, that's the user id (stringified
        // to match the `sub` claim the access token already carries).
        $response->assertExactJson([
            'sub' => (string) $this->user->id,
        ]);
    }

    public function test_userinfo_includes_profile_claims_when_profile_scope_granted(): void
    {
        $accessToken = $this->issueAccessToken('openid profile');

        $response = $this->withToken($accessToken)
            ->getJson('/api/oauth/userinfo')
            ->assertOk();

        $response->assertJsonPath('sub', (string) $this->user->id);
        $response->assertJsonPath('name', 'Ada Lovelace');
        $response->assertJsonPath('preferred_username', 'ada');

        // updated_at is the OIDC §5.4 numeric-time claim; must be the unix
        // timestamp (integer seconds), not the raw ISO string Laravel's
        // default JSON cast would emit.
        $this->assertIsInt($response->json('updated_at'));
        $this->assertSame(
            $this->user->updated_at->getTimestamp(),
            $response->json('updated_at'),
            'updated_at must be the unix timestamp, not an ISO string'
        );

        // email is owned by the `email` scope; it must NOT leak into a
        // profile-only response.
        $this->assertArrayNotHasKey('email', $response->json());
    }

    public function test_userinfo_includes_email_claims_when_email_scope_granted(): void
    {
        $accessToken = $this->issueAccessToken('openid email');

        $response = $this->withToken($accessToken)
            ->getJson('/api/oauth/userinfo')
            ->assertOk();

        $response->assertJsonPath('email', 'ada@example.com');
        $response->assertJsonPath('email_verified', true);

        // profile-only claims stay out of an email-only response.
        $this->assertArrayNotHasKey('name', $response->json());
        $this->assertArrayNotHasKey('preferred_username', $response->json());
    }

    public function test_userinfo_reports_email_verified_false_when_unverified(): void
    {
        $this->user->update(['email_verified_at' => null]);

        $accessToken = $this->issueAccessToken('openid email');
        $response = $this->withToken($accessToken)
            ->getJson('/api/oauth/userinfo')
            ->assertOk();

        $response->assertJsonPath('email_verified', false);
    }

    public function test_userinfo_emits_all_three_groups_when_all_scopes_granted(): void
    {
        $accessToken = $this->issueAccessToken('openid profile email');

        $response = $this->withToken($accessToken)
            ->getJson('/api/oauth/userinfo')
            ->assertOk();

        $response->assertJsonStructure([
            'sub',
            'name',
            'preferred_username',
            'updated_at',
            'email',
            'email_verified',
        ]);
    }

    public function test_userinfo_rejects_client_credentials_token(): void
    {
        // client_credentials tokens have no user, so the userinfo endpoint
        // must surface this as 403, not 200 with an empty body — a 200 would
        // let an RP silently treat the client as the user.
        $issued = $this->jwtService->generateClientToken('some-machine-client', 'client_credentials');
        $token = $issued['token'];

        $this->withToken($token)
            ->getJson('/api/oauth/userinfo')
            ->assertStatus(403)
            ->assertJsonPath('error', 'invalid_token');
    }

    public function test_userinfo_rejects_login_session_token(): void
    {
        // SSO-web login tokens (the kind /api/login issues to the SSO
        // frontend) carry a `sub` but no `client_id` — they authenticate
        // a user against the SSO itself, not against an external RP.
        // Presenting one to /oauth/userinfo must be a 403, not a 200
        // with just `sub`: a 200 would let an integration treat a
        // web-session token as an OIDC IdP access token.
        $token = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'password123',
        ])->assertOk()->json('token');

        $this->withToken($token)
            ->getJson('/api/oauth/userinfo')
            ->assertStatus(403)
            ->assertJsonPath('error', 'invalid_token');
    }

    /**
     * Issue a Bearer access token with the given scope string for the
     * test user. Uses the JWT service directly instead of going through
     * /oauth/authorize + /oauth/token because the userinfo endpoint is
     * scope-driven; the end-to-end scope plumbing is already covered by
     * OAuth2ScopeTest. The fake `client_id` mirrors what the OAuth2
     * token endpoint would stamp on a real authorization_code grant.
     */
    private function issueAccessToken(string $scope): string
    {
        return $this->jwtService->generateToken($this->user, null, false, null, $scope, 'test-rp')['token'];
    }
}
