<?php

namespace Tests\Feature;

use App\Models\SsoClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuth2RedirectUrlProtectionTest extends TestCase
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

    public function test_authorize_rejects_foreign_redirect_uri(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'test-app',
                'redirect_uri' => 'https://evil.com/callback',
                'response_type' => 'code',
            ]);

        $response->assertStatus(400)
            ->assertJson(['message' => 'redirect_uri no permitida']);
    }

    public function test_authorize_accepts_registered_redirect_uri(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'test-app',
                'redirect_uri' => 'https://app.example.com/auth/callback',
                'response_type' => 'code',
            ]);

        // Debe avanzar más allá de la validación de redirect_uri.
        // Sin autorización previa, devuelve authorization_required en lugar de un code.
        $response->assertOk()
            ->assertJsonPath('authorization_required', true);
    }

    public function test_authorize_rejects_client_without_redirect_url(): void
    {
        // Sin redirect_url no existe allowlist de callback: aceptar cualquier
        // redirect era el hueco que documentaba el test anterior. Ahora el SSO
        // exige que el cliente declare su callback.
        SsoClient::create([
            'name' => 'No Redirect App',
            'client_id' => 'no-redirect-app',
            'redirect_url' => null,
            'allowed_origins' => ['https://app.example.com'],
            'grant_types' => ['authorization_code'],
            'active' => true,
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'no-redirect-app',
                'redirect_uri' => 'https://app.example.com/auth/callback',
                'response_type' => 'code',
            ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('redirect_url', $response->json('message'));
    }

    public function test_authorize_rejects_redirect_origin_missing_from_allowed_origins(): void
    {
        // Config cruzada: el callback vive en 8102 y la allowlist solo tiene 8101.
        // Sin esta validación el SSO emitía el code, redirigía, y el canje del
        // token moría en CORS sin explicación visible.
        SsoClient::create([
            'name' => 'Crossed App',
            'client_id' => 'crossed-app',
            'redirect_url' => 'http://localhost:8102/auth/callback',
            'allowed_origins' => ['http://localhost:8101'],
            'grant_types' => ['authorization_code'],
            'active' => true,
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'crossed-app',
                'redirect_uri' => 'http://localhost:8102/auth/callback',
                'response_type' => 'code',
            ]);

        $response->assertStatus(400);
        // Estos tokens son el contrato del catálogo del front
        // (friendlyErrorCatalog matchea 'allowed_origins' + 'redirect_uri').
        $this->assertStringContainsString('allowed_origins', $response->json('message'));
        $this->assertStringContainsString('redirect_uri', $response->json('message'));
        $this->assertStringContainsString('http://localhost:8102', $response->json('message'));
    }

    public function test_approve_rejects_redirect_origin_missing_from_allowed_origins(): void
    {
        SsoClient::create([
            'name' => 'Crossed App',
            'client_id' => 'crossed-app',
            'redirect_url' => 'http://localhost:8102/auth/callback',
            'allowed_origins' => ['http://localhost:8101'],
            'grant_types' => ['authorization_code'],
            'active' => true,
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/oauth/authorize/approve', [
                'client_id' => 'crossed-app',
                'redirect_uri' => 'http://localhost:8102/auth/callback',
                'response_type' => 'code',
            ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('allowed_origins', $response->json('message'));
    }

    public function test_authorize_allows_custom_scheme_redirect_for_native_client(): void
    {
        // Las apps nativas redirigen con un scheme propio: su canje de token
        // no pasa por CORS, así que la validación de origen no aplica.
        SsoClient::create([
            'name' => 'Android App',
            'client_id' => 'native-app',
            'redirect_url' => 'com.example.app://auth/callback',
            'allowed_origins' => ['https://app.example.com'],
            'grant_types' => ['authorization_code'],
            'active' => true,
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'native-app',
                'redirect_uri' => 'com.example.app://auth/callback',
                'response_type' => 'code',
            ]);

        $response->assertOk()
            ->assertJsonPath('authorization_required', true);
    }

    public function test_authorize_does_not_block_confidential_client(): void
    {
        // Un cliente con secreto puede canjear el code server-to-server, donde
        // CORS no aplica: allowed_origins es la allowlist de quien llama la API,
        // no el host del callback. No debe bloquearse.
        SsoClient::create([
            'name' => 'BFF App',
            'client_id' => 'bff-app',
            'client_secret' => 'secret-123',
            'redirect_url' => 'https://app.example.com/auth/callback',
            'allowed_origins' => ['https://spa.example.com'],
            'grant_types' => ['authorization_code'],
            'active' => true,
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'bff-app',
                'redirect_uri' => 'https://app.example.com/auth/callback',
                'response_type' => 'code',
            ]);

        $response->assertOk()
            ->assertJsonPath('authorization_required', true);
    }

    public function test_authorize_accepts_redirect_on_frontend_url_origin(): void
    {
        // El middleware siempre agrega el origen del front del SSO a la allowlist.
        // La validación debe usar la misma lista efectiva o produce falsos positivos.
        config(['app.frontend_url' => 'https://sso.example.com']);

        SsoClient::create([
            'name' => 'Frontend App',
            'client_id' => 'frontend-app',
            'redirect_url' => 'https://sso.example.com/auth/callback',
            'allowed_origins' => ['https://other.example.com'],
            'grant_types' => ['authorization_code'],
            'active' => true,
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'frontend-app',
                'redirect_uri' => 'https://sso.example.com/auth/callback',
                'response_type' => 'code',
            ]);

        $response->assertOk()
            ->assertJsonPath('authorization_required', true);
    }

    public function test_approve_rejects_client_without_redirect_url(): void
    {
        SsoClient::create([
            'name' => 'No Redirect App',
            'client_id' => 'no-redirect-app',
            'redirect_url' => null,
            'allowed_origins' => ['https://app.example.com'],
            'grant_types' => ['authorization_code'],
            'active' => true,
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/oauth/authorize/approve', [
                'client_id' => 'no-redirect-app',
                'redirect_uri' => 'https://app.example.com/auth/callback',
                'response_type' => 'code',
            ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('redirect_url', $response->json('message'));
    }

    public function test_approve_rejects_foreign_redirect_uri(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/oauth/authorize/approve', [
                'client_id' => 'test-app',
                'redirect_uri' => 'https://evil.com/callback',
                'response_type' => 'code',
            ]);

        $response->assertStatus(400)
            ->assertJson(['message' => 'redirect_uri no permitida']);
    }

    public function test_approve_accepts_registered_redirect_uri(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/oauth/authorize/approve', [
                'client_id' => 'test-app',
                'redirect_uri' => 'https://app.example.com/auth/callback',
                'response_type' => 'code',
            ]);

        $response->assertOk()
            ->assertJsonStructure(['code']);
    }
}
