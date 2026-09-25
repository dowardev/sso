<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\SsoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
