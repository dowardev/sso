<?php

namespace Tests\Feature;

use App\Models\SsoClient;
use App\Models\User;
use App\Models\UserRefreshToken;
use App\Services\JwtService;
use App\Services\OAuth2Service;
use App\Services\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SsoSessionSyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $token;

    protected SsoClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $this->token = $response->json('token');

        $this->client = SsoClient::create([
            'name' => 'Test App',
            'client_id' => 'test-app',
            'redirect_url' => 'https://app.example.com/auth/callback',
            'allowed_origins' => ['https://app.example.com'],
            'grant_types' => ['authorization_code'],
            'active' => true,
        ]);
    }

    // --- Logout must revoke OAuth client tokens ------------------------------------

    public function test_logout_revokes_all_user_refresh_tokens(): void
    {
        $plain = Str::random(64);
        UserRefreshToken::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($this->token)
            ->postJson('/api/logout')
            ->assertOk();

        $stored = UserRefreshToken::where('token_hash', hash('sha256', $plain))->first();

        $this->assertNotNull($stored);
        $this->assertNotNull($stored->revoked_at);
    }

    public function test_logout_revokes_refresh_token_sent_in_body(): void
    {
        $plain = Str::random(64);
        UserRefreshToken::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($this->token)
            ->postJson('/api/logout', ['refreshToken' => $plain])
            ->assertOk();

        $stored = UserRefreshToken::where('token_hash', hash('sha256', $plain))->first();

        $this->assertNotNull($stored->revoked_at);
    }

    public function test_logout_revokes_oauth_client_sessions(): void
    {
        $clientToken = $this->createClientSessionToken();

        $this->withToken($this->token)
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertDatabaseMissing('user_sessions', [
            'token_hash' => hash('sha256', $clientToken),
        ]);

        $this->withToken($clientToken)
            ->getJson('/api/session/check')
            ->assertJson(['authenticated' => false]);
    }

    // --- /session/check must require a real, non-revoked session ------------------

    public function test_session_check_rejects_sso_user_id_without_token(): void
    {
        $this->postJson('/api/session/check', [
            'sso_user_id' => $this->user->id,
        ])->assertJson(['authenticated' => false]);
    }

    public function test_session_check_rejects_revoked_session(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/logout')
            ->assertOk();

        $this->withToken($this->token)
            ->getJson('/api/session/check')
            ->assertJson(['authenticated' => false]);
    }

    public function test_session_check_authenticates_active_session(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/session/check')
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.id', $this->user->id);
    }

    public function test_session_check_remains_authenticated_after_token_rotation(): void
    {
        $plain = Str::random(64);
        UserRefreshToken::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ]);

        $rotated = $this->postJson('/api/refresh-token', [
            'refreshToken' => $plain,
        ])->assertOk();

        $newToken = $rotated->json('token');

        // The session row keeps the original token hash; the user-level check
        // must not flag the rotated access token as a revoked session.
        $this->withToken($newToken)
            ->getJson('/api/session/check')
            ->assertOk()
            ->assertJsonPath('authenticated', true);
    }

    // --- OAuth refresh token flow --------------------------------------------------

    public function test_oauth_token_emits_refresh_token_on_authorization_code(): void
    {
        $auth = $this->createAuthCode();
        $response = $this->exchangeCode($auth['code'], $auth['verifier']);

        $response->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'expires_in', 'refresh_token', 'user']);
    }

    public function test_oauth_token_supports_refresh_token_grant(): void
    {
        $auth = $this->createAuthCode();
        $exchange = $this->exchangeCode($auth['code'], $auth['verifier']);

        $exchange->assertOk();
        $refreshToken = $exchange->json('refresh_token');

        $rotated = $this->postJson('/api/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => 'test-app',
            'refresh_token' => $refreshToken,
        ]);

        $rotated->assertOk()
            ->assertJsonStructure(['token', 'refresh_token'])
            ->assertJsonPath('user.id', $this->user->id);

        $old = UserRefreshToken::where('token_hash', hash('sha256', $refreshToken))->first();

        $this->assertNotNull($old->revoked_at);
        $this->assertNotSame($rotated->json('refresh_token'), $refreshToken);
    }

    public function test_refresh_token_endpoint_accepts_body_refresh_token(): void
    {
        $plain = Str::random(64);
        UserRefreshToken::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ]);

        $this->postJson('/api/refresh-token', [
            'refreshToken' => $plain,
        ])->assertOk()
            ->assertJsonStructure(['token', 'refresh_token']);
    }

    // --- Helpers -------------------------------------------------------------------

    private function createAuthCode(): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $result = app(OAuth2Service::class)->createAuthorizationCode($this->user, $this->client, [
            'redirect_uri' => 'https://app.example.com/auth/callback',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return ['code' => $result['code'], 'verifier' => $verifier];
    }

    private function exchangeCode(string $code, string $verifier): TestResponse
    {
        return $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'test-app',
            'code' => $code,
            'redirect_uri' => 'https://app.example.com/auth/callback',
            'code_verifier' => $verifier,
        ]);
    }

    private function createClientSessionToken(): string
    {
        $jwt = app(JwtService::class)->generateToken($this->user);
        $token = $jwt['token'];

        $request = Request::create('/api/oauth/token', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'test-client',
        ]);

        app(SessionService::class)->registerSession($this->user, $token, $request, null, false, $this->client);

        return $token;
    }
}
