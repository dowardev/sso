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
 * Pins the contract that claim_overrides cannot shadow the standard
 * OIDC claim set, in either the id_token or the /oauth/userinfo
 * response. The reserved-claim list covers both the OIDC §2 REQUIRED
 * claims (iss/sub/aud/exp/iat) and the scope-derived ones
 * (name/preferred_username/updated_at/email/email_verified) so a
 * per-client `email` override cannot make the two surfaces disagree
 * on what email value they emit.
 */
class OidcReservedClaimsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

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

        $this->client = SsoClient::create([
            'name' => 'Reserved RP',
            'client_id' => 'reserved-rp',
            'client_secret' => 'reserved-secret',
            'redirect_url' => 'https://reserved.example.com/auth/callback',
            'allowed_origins' => ['https://reserved.example.com'],
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

    public function test_id_token_blocks_shadow_attempts_on_scope_derived_claims(): void
    {
        // Without this guard an override named `email` would land in
        // the id_token (skipping the scope-derived claim block above)
        // while /oauth/userinfo would still emit the real email.
        // The two surfaces would then disagree, which is an
        // OIDC-conformance break a careful RP would notice.
        $this->client->update([
            'claim_overrides' => [
                'extra_claims' => [
                    'email' => 'attacker@example.com',
                    'name' => 'Forged Name',
                    'preferred_username' => 'forged-username',
                    'updated_at' => 1,
                ],
            ],
        ]);

        $parsed = $this->issueIdToken('openid profile email');

        $this->assertSame('ada@example.com', $parsed->claims()->get('email'));
        $this->assertSame('Ada Lovelace', $parsed->claims()->get('name'));
        $this->assertSame('ada', $parsed->claims()->get('preferred_username'));
        $this->assertIsInt($parsed->claims()->get('updated_at'));
        $this->assertNotSame(1, $parsed->claims()->get('updated_at'));
    }

    public function test_userinfo_blocks_shadow_attempts_on_scope_derived_claims(): void
    {
        // Mirror of the id_token test: an override named `email` must
        // not replace the real value the userinfo handler derives
        // from the User model.
        $this->client->update([
            'claim_overrides' => [
                'extra_claims' => [
                    'email' => 'attacker@example.com',
                    'name' => 'Forged Name',
                    'preferred_username' => 'forged-username',
                    'updated_at' => 1,
                ],
            ],
        ]);

        $accessToken = $this->issueAccessToken('openid profile email');
        $response = $this->withToken($accessToken)
            ->getJson('/api/oauth/userinfo')
            ->assertOk();

        $this->assertSame('ada@example.com', $response->json('email'));
        $this->assertSame('Ada Lovelace', $response->json('name'));
        $this->assertSame('ada', $response->json('preferred_username'));
        $this->assertIsInt($response->json('updated_at'));
        $this->assertNotSame(1, $response->json('updated_at'));
    }

    public function test_reserved_claims_list_includes_required_and_scope_derived(): void
    {
        $reserved = JwtService::reservedClaims();

        // OIDC §2 REQUIRED claims. Missing any of these is a spec
        // break — even an SSO misconfiguration should not be able to
        // override them.
        $this->assertContains('iss', $reserved);
        $this->assertContains('sub', $reserved);
        $this->assertContains('aud', $reserved);
        $this->assertContains('exp', $reserved);
        $this->assertContains('iat', $reserved);

        // Scope-derived claims. Without these in the reserved list an
        // override could shadow the userinfo value.
        $this->assertContains('name', $reserved);
        $this->assertContains('preferred_username', $reserved);
        $this->assertContains('updated_at', $reserved);
        $this->assertContains('email', $reserved);
        $this->assertContains('email_verified', $reserved);
    }

    private function issueIdToken(string $scope): Plain
    {
        $verifier = Str::random(64);
        $code = $this->withToken($this->postLogin()->json('token'))
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'reserved-rp',
                'redirect_uri' => 'https://reserved.example.com/auth/callback',
                'scope' => $scope,
                'code_challenge' => $this->codeChallengeFor($verifier),
                'code_challenge_method' => 'S256',
            ])->assertOk()->json('code');

        $tokenResponse = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'reserved-rp',
            'client_secret' => 'reserved-secret',
            'code' => $code,
            'redirect_uri' => 'https://reserved.example.com/auth/callback',
            'code_verifier' => $verifier,
        ])->assertOk();

        $parsed = $this->jwtService->parseAndValidateToken($tokenResponse->json('id_token'));
        $this->assertNotNull($parsed);

        return $parsed;
    }

    private function issueAccessToken(string $scope): string
    {
        $verifier = Str::random(64);
        $code = $this->withToken($this->postLogin()->json('token'))
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'reserved-rp',
                'redirect_uri' => 'https://reserved.example.com/auth/callback',
                'scope' => $scope,
                'code_challenge' => $this->codeChallengeFor($verifier),
                'code_challenge_method' => 'S256',
            ])->assertOk()->json('code');

        return $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'reserved-rp',
            'client_secret' => 'reserved-secret',
            'code' => $code,
            'redirect_uri' => 'https://reserved.example.com/auth/callback',
            'code_verifier' => $verifier,
        ])->assertOk()->json('token');
    }

    private function postLogin()
    {
        return $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'password123',
        ])->assertOk();
    }

    private function codeChallengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
