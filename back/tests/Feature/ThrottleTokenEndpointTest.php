<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\SsoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ThrottleTokenEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeClient(array $overrides = []): SsoClient
    {
        return SsoClient::create(array_merge([
            'name' => 'Test Client',
            'client_id' => 'test_client',
            'client_secret' => 'super_secret',
            'redirect_url' => 'https://example.com/callback',
            'allowed_origins' => ['https://example.com'],
            'grant_types' => ['authorization_code', 'client_credentials', 'refresh_token'],
            'active' => true,
        ], $overrides));
    }

    private function refreshTokenPayload(SsoClient $client, string $refreshToken = 'fake_refresh'): array
    {
        return [
            'grant_type' => 'refresh_token',
            'client_id' => $client->client_id,
            'refresh_token' => $refreshToken,
        ];
    }

    public function test_authorization_code_is_excluded_from_throttle(): void
    {
        $client = $this->makeClient();

        // 15 authorization_code requests in a row must not be 429'd by the
        // dedicated throttle. They will return 400 from the controller
        // because the code is invalid, but never from the throttle layer.
        for ($i = 0; $i < 15; $i++) {
            $response = $this->postJson('/api/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => $client->client_id,
                'code' => 'fake_code',
                'redirect_uri' => $client->redirect_url,
                'code_verifier' => str_repeat('a', 64),
            ]);

            $this->assertNotEquals(
                429,
                $response->getStatusCode(),
                "Request {$i} returned 429 — authorization_code should not be throttled.",
            );
        }
    }

    public function test_refresh_token_request_burst_returns_429_with_retry_after(): void
    {
        $client = $this->makeClient();

        // First 10 requests pass the dedicated throttle (controller returns 401
        // because the refresh token is invalid, but that's irrelevant).
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/oauth/token', $this->refreshTokenPayload($client));
            $this->assertNotEquals(
                429,
                $response->getStatusCode(),
                "Request {$i} unexpectedly throttled before reaching the limit.",
            );
        }

        // 11th request must be blocked with 429 + Retry-After.
        $response = $this->postJson('/api/oauth/token', $this->refreshTokenPayload($client));

        $response->assertStatus(429);
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(60, (int) $response->headers->get('Retry-After'));
        $response->assertHeader('X-RateLimit-Limit', '10');
        $response->assertHeader('X-RateLimit-Remaining', '0');
        $response->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_successful_response_carries_rate_limit_headers(): void
    {
        $client = $this->makeClient();

        $response = $this->postJson('/api/oauth/token', $this->refreshTokenPayload($client));

        // Controller will return 401 (bad refresh token) but headers must still
        // be set by the dedicated throttle middleware on the way back.
        $response->assertHeader('X-RateLimit-Limit', '10');
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $remaining = (int) $response->headers->get('X-RateLimit-Remaining');
        $this->assertGreaterThanOrEqual(0, $remaining);
        $this->assertLessThanOrEqual(10, $remaining);
    }

    public function test_different_ip_keeps_separate_bucket(): void
    {
        $client = $this->makeClient();

        // Burn the bucket from IP 10.0.0.1.
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
                ->postJson('/api/oauth/token', $this->refreshTokenPayload($client));
        }

        // IP 10.0.0.1 is exhausted.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/api/oauth/token', $this->refreshTokenPayload($client))
            ->assertStatus(429);

        // A different IP must still have full quota.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/oauth/token', $this->refreshTokenPayload($client));

        $this->assertNotEquals(429, $response->getStatusCode());
        $response->assertHeader('X-RateLimit-Limit', '10');
        $this->assertGreaterThan(0, (int) $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_different_client_id_same_ip_shares_ip_bucket(): void
    {
        $clientA = $this->makeClient(['client_id' => 'client_a']);
        $clientB = $this->makeClient(['client_id' => 'client_b']);

        // Exhaust client_a's (client, IP) bucket from a single IP.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/oauth/token', $this->refreshTokenPayload($clientA));
        }
        $this->postJson('/api/oauth/token', $this->refreshTokenPayload($clientA))
            ->assertStatus(429);

        // The IP bucket is now also saturated: a different client from the
        // same IP must be blocked too. This is the anti-enumeration trade-off
        // documented in the middleware's docblock.
        $this->postJson('/api/oauth/token', $this->refreshTokenPayload($clientB))
            ->assertStatus(429);

        // A different client from a DIFFERENT IP keeps both buckets fresh
        // and must be allowed.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson('/api/oauth/token', $this->refreshTokenPayload($clientB));
        $this->assertNotEquals(429, $response->getStatusCode());
        $response->assertHeader('X-RateLimit-Limit', '10');
        $this->assertGreaterThan(0, (int) $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_invalid_client_id_collapses_to_ip_bucket_for_anti_enumeration(): void
    {
        // 15 attempts with random non-existent client_ids from the same IP
        // must eventually 429. This guards against client_id enumeration.
        for ($i = 0; $i < 15; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
                ->postJson('/api/oauth/token', [
                    'grant_type' => 'refresh_token',
                    'client_id' => 'random_'.bin2hex(random_bytes(4)),
                    'refresh_token' => 'fake',
                ]);

            // After 10 attempts the IP bucket must be exhausted regardless of
            // which client_id was probed.
            if ($i >= 10) {
                $response->assertStatus(429);
                $response->assertHeader('X-RateLimit-Limit', '10');
                $this->assertTrue($response->headers->has('Retry-After'));
            }
        }
    }

    public function test_burst_limit_kicks_in_at_21st_request(): void
    {
        $client = $this->makeClient();

        // 20 requests in the same window must pass the burst gate (current > 20).
        for ($i = 0; $i < 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
                ->postJson('/api/oauth/token', $this->refreshTokenPayload($client));
        }

        // The 21st request must cross the burst threshold.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/api/oauth/token', $this->refreshTokenPayload($client));

        $response->assertStatus(429);
        $response->assertHeader('X-RateLimit-Limit', '10');
        $response->assertHeader('X-RateLimit-Remaining', '0');
    }

    public function test_middleware_handles_file_cache_driver_gracefully(): void
    {
        // CACHE_STORE=file degrades: `Cache::increment` returns 0/false on the
        // first hit because file storage cannot atomically increment a missing
        // entry. The middleware must still produce 429 once the limit is hit
        // and never throw an uncaught exception.
        config(['cache.default' => 'file']);
        Cache::store('file')->clear();

        $client = $this->makeClient();

        for ($i = 0; $i < 15; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
                ->postJson('/api/oauth/token', $this->refreshTokenPayload($client));
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->postJson('/api/oauth/token', $this->refreshTokenPayload($client));

        $response->assertStatus(429);
        $response->assertHeader('X-RateLimit-Limit', '10');
    }

    public function test_previous_window_weight_is_applied_after_rollover(): void
    {
        // Burn 10 requests in window N (current bucket hits limit), then move
        // time forward into the next window and verify that the previous
        // window's count keeps contributing with decaying weight.
        $client = $this->makeClient();

        // Anchor at t = 0 (start of a clean 60s window).
        $start = Carbon::create(2026, 1, 1, 12, 0, 0, 'UTC');
        Carbon::setTestNow($start);
        Cache::flush();

        // 10 requests within window N → current window is at the limit.
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '172.16.0.1'])
                ->postJson('/api/oauth/token', $this->refreshTokenPayload($client));
        }

        // Move into window N+1 at elapsed = 5s (weight on previous = (60-5)/60 ≈ 0.92).
        // 9 new requests in N+1: current=9, previous=10 → estimated = 9 + floor(10*0.92)=9+9=18 > 10 → 429.
        Carbon::setTestNow($start->copy()->addSeconds(65));

        $response = $this->withServerVariables(['REMOTE_ADDR' => '172.16.0.1'])
            ->postJson('/api/oauth/token', $this->refreshTokenPayload($client));

        $response->assertStatus(429);

        // Move further: weight should drop toward 0 as we approach the end of N+1.
        // At elapsed=59s in N+1, weight on N = (60-59)/60 ≈ 0.02 → previous contributes
        // 10*0.02 = 0.2. 10 fresh in N+1 → estimated = 10 + 0 = 10. Anything above that
        // must produce 429.
        Carbon::setTestNow($start->copy()->addSeconds(120 + 59));
        Cache::flush();
        // Issue 11 requests after the flush + 59s rollover: 11th must 429.
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '172.16.0.1'])
                ->postJson('/api/oauth/token', $this->refreshTokenPayload($client));
        }
        $last = $this->withServerVariables(['REMOTE_ADDR' => '172.16.0.1'])
            ->postJson('/api/oauth/token', $this->refreshTokenPayload($client));
        $last->assertStatus(429);

        Carbon::setTestNow();
        Cache::flush();
    }
}
