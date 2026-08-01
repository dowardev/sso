<?php

namespace Tests\Feature;

use App\Models\SsoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SsoClientObserverTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'test-client-123';

    private const EXPECTED_KEY = 'sso_cors_origins_test-client-123';

    private const FALLBACK_KEY = 'sso_cors_origins___sso__';

    private const STALE_KEY = 'sso_cors_origins';

    public function test_create_sso_client_invalidates_expected_cache_keys(): void
    {
        Cache::put(self::EXPECTED_KEY, ['https://old.com'], 60);
        Cache::put(self::FALLBACK_KEY, ['https://old.com'], 60);
        Cache::put(self::STALE_KEY, ['https://stale.com'], 60);

        SsoClient::create([
            'name' => 'Test Client',
            'client_id' => self::CLIENT_ID,
            'client_secret' => 'secret-123',
            'allowed_origins' => ['https://example.com'],
            'active' => true,
        ]);

        $this->assertNull(Cache::get(self::EXPECTED_KEY));
        $this->assertNull(Cache::get(self::FALLBACK_KEY));
    }

    public function test_create_sso_client_does_not_touch_stale_cache_key(): void
    {
        Cache::put(self::STALE_KEY, ['https://stale.com'], 60);

        SsoClient::create([
            'name' => 'Another Client',
            'client_id' => 'another-client',
            'client_secret' => 'secret-456',
            'allowed_origins' => ['https://app.com'],
            'active' => true,
        ]);

        $this->assertNotNull(Cache::get(self::STALE_KEY));
    }

    public function test_update_sso_client_invalidates_expected_cache_keys(): void
    {
        $client = SsoClient::create([
            'name' => 'Old Name',
            'client_id' => self::CLIENT_ID,
            'client_secret' => 'secret-123',
            'allowed_origins' => ['https://example.com'],
            'active' => true,
        ]);

        Cache::put(self::EXPECTED_KEY, ['https://old.com'], 60);
        Cache::put(self::FALLBACK_KEY, ['https://old.com'], 60);

        $client->update(['name' => 'Updated Name']);

        $this->assertNull(Cache::get(self::EXPECTED_KEY));
        $this->assertNull(Cache::get(self::FALLBACK_KEY));
    }

    public function test_delete_sso_client_invalidates_expected_cache_keys(): void
    {
        $client = SsoClient::create([
            'name' => 'To Delete',
            'client_id' => self::CLIENT_ID,
            'client_secret' => 'secret-123',
            'allowed_origins' => ['https://example.com'],
            'active' => true,
        ]);

        Cache::put(self::EXPECTED_KEY, ['https://old.com'], 60);
        Cache::put(self::FALLBACK_KEY, ['https://old.com'], 60);

        $client->delete();

        $this->assertNull(Cache::get(self::EXPECTED_KEY));
        $this->assertNull(Cache::get(self::FALLBACK_KEY));
    }

    public function test_sso_client_without_allowed_origins_still_invalidates_cache(): void
    {
        Cache::put('sso_cors_origins_no-origins-client', ['https://old.com'], 60);
        Cache::put(self::FALLBACK_KEY, ['https://old.com'], 60);

        SsoClient::create([
            'name' => 'No Origins',
            'client_id' => 'no-origins-client',
            'client_secret' => 'secret-789',
            'active' => true,
        ]);

        $this->assertNull(Cache::get('sso_cors_origins_no-origins-client'));
        $this->assertNull(Cache::get(self::FALLBACK_KEY));
    }
}
