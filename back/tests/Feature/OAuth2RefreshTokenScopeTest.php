<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\OauthClientAuthorization;
use App\Models\SsoClient;
use App\Models\User;
use App\Services\JwtService;
use App\Services\OAuth2Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\DpopProofBuilder;
use Tests\TestCase;

/**
 * Pins the contract that a refresh token rotates into an access token
 * that carries the same OAuth2 scope and the originating client_id as
 * the original token. Without this, /oauth/userinfo silently drops
 * profile/email claims and the per-client claim_overrides after the
 * first rotation, because both depend on those two claims landing in
 * the access token. Regression test for the LBDS-303 review finding
 * (F1) that surfaced this gap.
 */
class OAuth2RefreshTokenScopeTest extends TestCase
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
            'name' => 'Refresh RP',
            'client_id' => 'refresh-rp',
            'client_secret' => 'refresh-secret',
            'redirect_url' => 'https://refresh.example.com/auth/callback',
            'allowed_origins' => ['https://refresh.example.com'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'allowed_scopes' => ['openid', 'profile', 'email', 'offline_access'],
            'claim_overrides' => [
                'extra_claims' => [
                    'tenant_id' => 'acme-corp',
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

    public function test_refresh_token_preserves_scope_in_rotated_access_token(): void
    {
        $builder = DpopProofBuilder::generate();
        ['access' => $initialAccess, 'refresh' => $refresh] = $this->issueBoundTokens($builder, 'openid profile email');

        $initialParsed = $this->jwtService->parseAndValidateToken($initialAccess);
        $this->assertSame('openid profile email', $initialParsed->claims()->get('scope'));

        $rotated = $this->refresh($refresh, $builder->proof());
        $rotated->assertOk();

        $rotatedParsed = $this->jwtService->parseAndValidateToken($rotated->json('token'));

        $this->assertSame(
            'openid profile email',
            $rotatedParsed->claims()->get('scope'),
            'rotated access token must keep the scope the RP was granted, otherwise /oauth/userinfo drops profile/email claims after the first rotation'
        );
    }

    public function test_refresh_token_preserves_client_id_in_rotated_access_token(): void
    {
        // The userinfo endpoint resolves per-client claim_overrides via
        // the access token's `client_id` claim. Without inheritance from
        // the refresh token row, the rotated token would arrive at
        // /oauth/userinfo with no client_id and every claim_override
        // would silently disappear.
        $builder = DpopProofBuilder::generate();
        ['refresh' => $refresh] = $this->issueBoundTokens($builder, 'openid');

        $rotated = $this->refresh($refresh, $builder->proof());
        $rotated->assertOk();

        $rotatedParsed = $this->jwtService->parseAndValidateToken($rotated->json('token'));
        $this->assertSame('refresh-rp', $rotatedParsed->claims()->get('client_id'));
    }

    public function test_rotated_access_token_keeps_claim_overrides_active(): void
    {
        // End-to-end: a /oauth/userinfo call after a refresh must still
        // surface the per-client `tenant_id` claim. If the rotated
        // token lost `client_id`, the userinfo handler would skip the
        // override and the RP would see the claim vanish mid-session.
        $builder = DpopProofBuilder::generate();
        ['refresh' => $refresh] = $this->issueBoundTokens($builder, 'openid profile');

        $rotated = $this->refresh($refresh, $builder->proof());
        $rotated->assertOk();

        $rotatedToken = $rotated->json('token');
        $userinfoProof = $builder->proof([
            'htm' => 'GET',
            'htu' => 'http://localhost/api/oauth/userinfo',
            'ath' => DpopProofBuilder::ath($rotatedToken),
        ]);

        $response = $this->withHeaders(['DPoP' => $userinfoProof])
            ->withToken($rotatedToken)
            ->getJson('/api/oauth/userinfo')
            ->assertOk();

        $response->assertJsonPath('tenant_id', 'acme-corp');
        $response->assertJsonPath('name', 'Ada Lovelace');
    }

    /**
     * Run a full /oauth/authorize + /oauth/token flow with the given
     * scope and a DPoP binding; return both the access and refresh
     * tokens. Mirrors the helper in DpopRefreshTokenTest.
     *
     * @return array{access: string, refresh: string}
     */
    private function issueBoundTokens(DpopProofBuilder $builder, string $scope): array
    {
        $verifier = Str::random(64);
        $auth = app(OAuth2Service::class)->createAuthorizationCode($this->user, $this->client, [
            'redirect_uri' => 'https://refresh.example.com/auth/callback',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], $scope);

        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'refresh-rp',
            'client_secret' => 'refresh-secret',
            'code' => $auth['code'],
            'redirect_uri' => 'https://refresh.example.com/auth/callback',
            'code_verifier' => $verifier,
            'dpop_public_key' => $builder->publicKey(),
        ]);

        $response->assertOk();

        return [
            'access' => $response->json('token'),
            'refresh' => $response->json('refresh_token'),
        ];
    }

    private function refresh(string $refreshToken, string $dpopHeader)
    {
        return $this->withHeaders(['DPoP' => $dpopHeader])
            ->postJson('/api/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => 'refresh-rp',
                'client_secret' => 'refresh-secret',
                'refresh_token' => $refreshToken,
            ]);
    }
}
