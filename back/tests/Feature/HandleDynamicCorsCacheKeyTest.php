<?php

namespace Tests\Feature;

use App\Models\SsoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HandleDynamicCorsCacheKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_middleware_uses_fallback_key_for_anonymous_request(): void
    {
        $this->get('/api/setup/status', ['Origin' => 'https://app.example.com']);

        $this->assertNotNull(Cache::get('sso_cors_origins___sso__'));
    }

    public function test_middleware_uses_client_id_key_from_request_body(): void
    {
        SsoClient::create([
            'name' => 'Test Client',
            'client_id' => 'my-client',
            'client_secret' => 'secret-123',
            'allowed_origins' => ['https://app.example.com'],
            'active' => true,
        ]);

        $this->post('/api/oauth/token', [
            'client_id' => 'my-client',
            'grant_type' => 'client_credentials',
        ], ['Origin' => 'https://app.example.com']);

        $this->assertNotNull(Cache::get('sso_cors_origins_my-client'));
    }

    public function test_frontend_url_config_is_included_in_allowed_origins(): void
    {
        $frontendUrl = 'https://admin.example.com';
        config(['app.frontend_url' => $frontendUrl]);

        $response = $this->get('/api/setup/status', ['Origin' => $frontendUrl]);

        $response->assertHeader('Access-Control-Allow-Origin', $frontendUrl);
    }

    public function test_middleware_drops_origin_not_in_allowed_list(): void
    {
        $response = $this->get('/api/setup/status', ['Origin' => 'https://evil.com']);

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
