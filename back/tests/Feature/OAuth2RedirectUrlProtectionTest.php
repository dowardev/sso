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

    public function test_authorize_without_redirect_url_accepts_any_redirect(): void
    {
        // Cliente sin redirect_url configurado (el caso vulnerable actual en prod).
        $clientWithoutRedirect = SsoClient::create([
            'name' => 'Vulnerable App',
            'client_id' => 'vulnerable-app',
            'redirect_url' => null,
            'allowed_origins' => ['https://app.example.com'],
            'grant_types' => ['authorization_code'],
            'active' => true,
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/oauth/authorize', [
                'client_id' => 'vulnerable-app',
                'redirect_uri' => 'https://evil.com/callback',
                'response_type' => 'code',
            ]);

        // Sin redirect_url, la validación se salta y el flujo avanza:
        // esto demuestra el hueco que queremos cerrar.
        $response->assertOk()
            ->assertJsonPath('authorization_required', true);
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
