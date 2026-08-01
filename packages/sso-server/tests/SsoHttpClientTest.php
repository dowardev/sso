<?php

namespace Dowardev\SsoServer\Tests;

use Dowardev\SsoServer\Http\SsoHttpClient;
use Dowardev\SsoServer\Types\SsoConfig;
use Illuminate\Support\Facades\Http;

class SsoHttpClientTest extends TestCase
{
    private SsoHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $config = SsoConfig::fromConfig();
        $this->client = new SsoHttpClient($config);
    }

    public function test_get_me_includes_client_id(): void
    {
        Http::fake([
            'http://localhost:8001/api/me*' => Http::response(['roles' => []], 200),
        ]);

        $this->client->get('/me', 'test-token');

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, '/me') && str_contains($url, 'client_id=1');
        });
    }

    public function test_get_other_route_excludes_client_id(): void
    {
        Http::fake([
            'http://localhost:8001/api/roles*' => Http::response([], 200),
        ]);

        $this->client->get('/roles', 'test-token');

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, '/roles') && ! str_contains($url, 'client_id');
        });
    }

    public function test_url_with_existing_query_params_uses_ampersand(): void
    {
        Http::fake([
            'http://localhost:8001/api/me?existing=1*' => Http::response(['roles' => []], 200),
        ]);

        $config = new SsoConfig(
            ssoUrl: 'http://localhost:8001/api/me?existing=1',
            jwtIssuer: 'http://localhost:8001',
            clientId: '1',
        );
        $client = new SsoHttpClient($config);

        $client->get('/me', 'test-token');

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, '&client_id=1');
        });
    }
}
