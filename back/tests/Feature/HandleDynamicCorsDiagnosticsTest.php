<?php

namespace Tests\Feature;

use App\Models\SsoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandleDynamicCorsDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejected_origin_includes_diagnostic_headers(): void
    {
        SsoClient::create([
            'name' => 'Test Client',
            'client_id' => 'diag-client',
            'client_secret' => 'secret-123',
            'allowed_origins' => ['https://app.example.com'],
            'active' => true,
        ]);

        $response = $this->post('/api/oauth/token', [
            'client_id' => 'diag-client',
            'grant_type' => 'client_credentials',
        ], ['Origin' => 'https://unknown.example.com']);

        $response->assertHeader('X-CORS-Rejected', 'origin-not-allowed');
        $response->assertHeader('X-CORS-Origin', 'https://unknown.example.com');
        $response->assertHeader('X-CORS-Client', 'diag-client');
        $this->assertStringContainsString(
            'https://app.example.com',
            $response->headers->get('X-CORS-Allowed')
        );
        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_rejected_preflight_returns_204_with_diagnostic_headers(): void
    {
        SsoClient::create([
            'name' => 'Test Client',
            'client_id' => 'diag-client',
            'client_secret' => 'secret-123',
            'allowed_origins' => ['https://app.example.com'],
            'active' => true,
        ]);

        $response = $this->call('OPTIONS', '/api/oauth/token', [], [], [], [
            'HTTP_ORIGIN' => 'https://unknown.example.com',
        ]);

        // A rejected preflight still answers as a preflight, not as a 405.
        $response->assertStatus(204);
        $response->assertHeader('X-CORS-Rejected', 'origin-not-allowed');
        $response->assertHeader('X-CORS-Origin', 'https://unknown.example.com');
        $response->assertHeader('Vary', 'Origin');
        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_allowed_preflight_returns_204_with_cors_headers(): void
    {
        SsoClient::create([
            'name' => 'Test Client',
            'client_id' => 'diag-client',
            'client_secret' => 'secret-123',
            'allowed_origins' => ['https://app.example.com'],
            'active' => true,
        ]);

        $response = $this->call('OPTIONS', '/api/oauth/token', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.example.com',
        ]);

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
        $response->assertHeaderMissing('X-CORS-Rejected');
    }

    public function test_rejected_origin_without_client_id_omits_allowed_list(): void
    {
        // Sin client_id resoluble la allowlist sería la unión de todos los
        // clientes activos: no se publica.
        $response = $this->get('/api/setup/status', ['Origin' => 'https://evil.example.com']);

        $response->assertHeader('X-CORS-Rejected', 'origin-not-allowed');
        $response->assertHeaderMissing('X-CORS-Allowed');
        $response->assertHeaderMissing('X-CORS-Client');
        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_allowed_origin_has_no_diagnostic_headers(): void
    {
        SsoClient::create([
            'name' => 'Test Client',
            'client_id' => 'diag-client',
            'client_secret' => 'secret-123',
            'allowed_origins' => ['https://app.example.com'],
            'active' => true,
        ]);

        $response = $this->get('/api/setup/status', ['Origin' => 'https://app.example.com']);

        $response->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
        $response->assertHeaderMissing('X-CORS-Rejected');
    }
}
