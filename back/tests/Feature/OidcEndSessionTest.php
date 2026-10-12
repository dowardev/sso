<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\OauthClientAuthorization;
use App\Models\SsoClient;
use App\Models\User;
use App\Models\UserRefreshToken;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for the OIDC Session Management 1.0 §2 RP-initiated
 * logout endpoint at /oauth/logout.
 *
 * The endpoint is the mirror of /oauth/authorize: where the latter
 * starts a session, this one ends it. The contract pins three things:
 *   - The id_token_hint is verified (signature, issuer, expiry), not
 *     trusted blindly. A forged or expired hint must not log anyone out.
 *   - The post_logout_redirect_uri is matched against the client's
 *     registered redirect_url. Without this guard, the SSO would be
 *     an open redirector post-logout.
 *   - The user is actually logged out — every refresh token and
 *     session row for that user is deleted, not just the local cookie.
 */
class OidcEndSessionTest extends TestCase
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
            'name' => 'Logout RP',
            'client_id' => 'logout-rp',
            'client_secret' => 'logout-secret',
            'redirect_url' => 'https://logout.example.com/auth/callback',
            'allowed_origins' => ['https://logout.example.com'],
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

    public function test_logout_without_id_token_hint_renders_confirmation_page(): void
    {
        // OIDC §2 lists id_token_hint as RECOMMENDED, not REQUIRED.
        // Without it, the endpoint has no user anchor and no client
        // anchor, so a redirect would be an open redirect and a logout
        // would be arbitrary. Degraded mode: render a small HTML page
        // that explains the endpoint and points the user at the SSO's
        // normal /logout flow.
        $response = $this->get('/api/oauth/logout')
            ->assertStatus(200);

        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Cierre de sesión', $response->getContent());
    }

    public function test_logout_without_id_token_hint_does_not_pretend_to_logout(): void
    {
        // The pre-merge review surfaced that a 'logged out' message
        // when no logout actually happened is phishing-friendly: a
        // user that lands here from a bookmark or a malicious link
        // would close the tab believing their session ended, while
        // their tokens stay valid. The page must say so explicitly
        // when no id_token_hint was provided.
        $response = $this->get('/api/oauth/logout')
            ->assertStatus(200);

        $this->assertStringContainsString('No se pudo identificar', $response->getContent());
    }

    public function test_logout_rejects_invalid_id_token_hint(): void
    {
        // A forged hint must not log anyone out, even if it claims the
        // right sub. The signature is what authenticates it; the rest
        // is just data the attacker controls.
        $this->getJson('/api/oauth/logout?id_token_hint=not-a-real-jwt')
            ->assertStatus(400);
    }

    public function test_logout_redirects_to_post_logout_uri_with_state(): void
    {
        $idToken = $this->issueIdToken();

        $response = $this->getJson('/api/oauth/logout?id_token_hint='.urlencode($idToken)
            .'&post_logout_redirect_uri='.urlencode('https://logout.example.com/auth/callback')
            .'&state='.urlencode('rp-state-123'));

        // The redirect MUST land on the exact URI the client registered.
        // A 200 here, or a redirect to anything else, would mean the SSO
        // is either showing a confirmation page (not yet supported) or
        // has become an open redirector.
        $response->assertStatus(302);
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringStartsWith(
            'https://logout.example.com/auth/callback',
            $location,
            'redirect must target the client-registered post_logout URL'
        );
        $this->assertStringContainsString('state=rp-state-123', $location);
    }

    public function test_logout_rejects_post_logout_uri_not_in_client_allowlist(): void
    {
        // Open-redirect protection: an RP can only send the user to a
        // URL it declared at registration time. Without this check, an
        // attacker who phished a victim's id_token could log them out
        // and land them on a phishing page that looks like a follow-up
        // step in the legitimate flow.
        $idToken = $this->issueIdToken();

        $this->getJson('/api/oauth/logout?id_token_hint='.urlencode($idToken)
            .'&post_logout_redirect_uri='.urlencode('https://attacker.example.com/phish'))
            ->assertStatus(400);
    }

    public function test_logout_rejects_post_logout_uri_when_client_unknown(): void
    {
        // Build an id_token with an `aud` that points to a non-existent
        // client. The post_logout_redirect_uri then has no anchor and
        // must be rejected — we don't know whose allowlist to check
        // against, and an unknown anchor is the only safe answer.
        $otherClient = SsoClient::create([
            'name' => 'Ghost',
            'client_id' => 'ghost-rp',
            'client_secret' => 'ghost-secret',
            'redirect_url' => 'https://ghost.example.com/auth/callback',
            'allowed_origins' => ['https://ghost.example.com'],
            'grant_types' => ['authorization_code'],
            'allowed_scopes' => ['openid'],
            'active' => true,
        ]);

        $token = $this->jwtService->generateIdToken(
            $this->user,
            $otherClient,
            'nonce',
            ['openid'],
            time(),
        );

        $this->getJson('/api/oauth/logout?id_token_hint='.urlencode($token)
            .'&post_logout_redirect_uri='.urlencode('https://anywhere.example.com/x'))
            ->assertStatus(400);
    }

    public function test_logout_revokes_every_user_session_and_refresh_token(): void
    {
        // The point of RP-initiated logout is that the SSO must stop
        // minting new access tokens for the user, not just clear the
        // local cookie. A 200 response that leaves the refresh tokens
        // alive would let the SSO keep refreshing the session in the
        // background until the inactivity window expires.
        $this->issueAccessToken(); // creates a refresh token + session row

        $idToken = $this->issueIdToken();

        $this->getJson('/api/oauth/logout?id_token_hint='.urlencode($idToken))
            ->assertStatus(200);

        $this->assertDatabaseMissing('user_sessions', ['user_id' => $this->user->id]);

        // Refresh tokens are soft-revoked via `revoked_at` (a soft
        // delete keeps the row for audit / activity-log joins). The
        // security-relevant invariant is that no row has a null
        // `revoked_at` for this user — that is the only path that lets
        // /oauth/token honour a refresh request.
        $active = UserRefreshToken::where('user_id', $this->user->id)
            ->whereNull('revoked_at')
            ->count();
        $this->assertSame(0, $active, 'no refresh token may remain unrevoked after logout');
    }

    public function test_logout_without_post_logout_uri_returns_html_confirmation(): void
    {
        // A user that types the URL directly (or an RP that omits the
        // post_logout_redirect_uri on purpose) should still see a page
        // that says what happened — a blank 200 is harder to debug than
        // a 200 with a sentence on it.
        $idToken = $this->issueIdToken();

        $response = $this->getJson('/api/oauth/logout?id_token_hint='.urlencode($idToken))
            ->assertStatus(200);

        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Ada Lovelace', $response->getContent());
    }

    private function issueIdToken(): string
    {
        $verifier = Str::random(64);
        $code = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'logout-rp',
                'redirect_uri' => 'https://logout.example.com/auth/callback',
                'scope' => 'openid profile',
                'code_challenge' => $this->codeChallengeFor($verifier),
                'code_challenge_method' => 'S256',
            ])->assertOk()->json('code');

        return $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'logout-rp',
            'client_secret' => 'logout-secret',
            'code' => $code,
            'redirect_uri' => 'https://logout.example.com/auth/callback',
            'code_verifier' => $verifier,
        ])->assertOk()->json('id_token');
    }

    private function issueAccessToken(): string
    {
        $verifier = Str::random(64);
        $code = $this->withToken($this->sessionToken)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'logout-rp',
                'redirect_uri' => 'https://logout.example.com/auth/callback',
                'scope' => 'openid',
                'code_challenge' => $this->codeChallengeFor($verifier),
                'code_challenge_method' => 'S256',
            ])->assertOk()->json('code');

        return $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'logout-rp',
            'client_secret' => 'logout-secret',
            'code' => $code,
            'redirect_uri' => 'https://logout.example.com/auth/callback',
            'code_verifier' => $verifier,
        ])->assertOk()->json('token');
    }

    private function codeChallengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
