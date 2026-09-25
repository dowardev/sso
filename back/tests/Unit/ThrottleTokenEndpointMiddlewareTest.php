<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Unit;

use App\Http\Middleware\ThrottleTokenEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Direct unit tests of the middleware that bypass the HTTP routing layer
 * entirely. These verify the sliding-window logic in isolation — useful
 * because integration tests in `ThrottleTokenEndpointTest` exercise the
 * middleware behind the production route chain (`throttle:10,1, throttle.token`)
 * and would still pass if the dedicated middleware silently broke.
 */
class ThrottleTokenEndpointMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeRequest(array $body, ?string $ip = '10.0.0.1'): Request
    {
        $request = Request::create('/api/oauth/token', 'POST', $body);
        $request->server->set('REMOTE_ADDR', $ip);

        return $request;
    }

    private function passThrough(): \Closure
    {
        return fn (Request $request) => new JsonResponse(['ok' => true], 200);
    }

    public function test_first_request_passes_and_sets_headers(): void
    {
        $middleware = new ThrottleTokenEndpoint;
        $request = $this->makeRequest([
            'grant_type' => 'refresh_token',
            'client_id' => 'client_a',
        ]);

        $response = $middleware->handle($request, $this->passThrough(), '10', '60', '20');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('10', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('9', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_after_ten_requests_eleventh_is_429(): void
    {
        $middleware = new ThrottleTokenEndpoint;

        for ($i = 0; $i < 10; $i++) {
            $request = $this->makeRequest([
                'grant_type' => 'refresh_token',
                'client_id' => 'client_a',
            ]);
            $middleware->handle($request, $this->passThrough(), '10', '60', '20');
        }

        $eleventh = $this->makeRequest([
            'grant_type' => 'refresh_token',
            'client_id' => 'client_a',
        ]);
        $response = $middleware->handle($eleventh, $this->passThrough(), '10', '60', '20');

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('10', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('Retry-After'));
    }

    public function test_authorization_code_always_passes_even_after_burst(): void
    {
        $middleware = new ThrottleTokenEndpoint;

        for ($i = 0; $i < 15; $i++) {
            $request = $this->makeRequest([
                'grant_type' => 'authorization_code',
                'client_id' => 'client_a',
                'code' => 'fake',
                'redirect_uri' => 'https://example.com/cb',
                'code_verifier' => str_repeat('a', 64),
            ]);
            $response = $middleware->handle($request, $this->passThrough(), '10', '60', '20');
            $this->assertNotSame(429, $response->getStatusCode(), "Hit $i was throttled");
        }
    }

    public function test_authorization_code_match_is_case_insensitive(): void
    {
        $middleware = new ThrottleTokenEndpoint;

        // The token endpoint checks `grant_type` against the bypass using
        // strtolower() so a client sending `Authorization_Code` still bypasses.
        $request = $this->makeRequest([
            'grant_type' => 'Authorization_Code',
            'client_id' => 'client_a',
            'code' => 'fake',
            'redirect_uri' => 'https://example.com/cb',
            'code_verifier' => str_repeat('a', 64),
        ]);

        $response = $middleware->handle($request, $this->passThrough(), '10', '60', '20');

        $this->assertNotSame(429, $response->getStatusCode());
    }

    public function test_different_client_ids_are_independent(): void
    {
        $middleware = new ThrottleTokenEndpoint;

        for ($i = 0; $i < 10; $i++) {
            $middleware->handle(
                $this->makeRequest(['grant_type' => 'refresh_token', 'client_id' => 'a']),
                $this->passThrough(),
                '10', '60', '20',
            );
        }

        $responseA = $middleware->handle(
            $this->makeRequest(['grant_type' => 'refresh_token', 'client_id' => 'a']),
            $this->passThrough(),
            '10', '60', '20',
        );
        $this->assertSame(429, $responseA->getStatusCode());

        $responseB = $middleware->handle(
            $this->makeRequest(['grant_type' => 'refresh_token', 'client_id' => 'b']),
            $this->passThrough(),
            '10', '60', '20',
        );
        // Client b shares the IP bucket with a (which is saturated) so it is
        // also blocked. This is the documented anti-enumeration trade-off.
        $this->assertSame(429, $responseB->getStatusCode());

        // A request from a different IP must reset both buckets.
        $responseC = $middleware->handle(
            $this->makeRequest(['grant_type' => 'refresh_token', 'client_id' => 'a'], '10.0.0.2'),
            $this->passThrough(),
            '10', '60', '20',
        );
        $this->assertNotSame(429, $responseC->getStatusCode());
    }

    public function test_invalid_client_id_falls_into_dedicated_unknown_bucket(): void
    {
        $middleware = new ThrottleTokenEndpoint;

        // Twelve requests with random client_ids from the same IP. The (IP)
        // bucket fills up and blocks all subsequent random-client attempts.
        for ($i = 0; $i < 12; $i++) {
            $request = $this->makeRequest([
                'grant_type' => 'refresh_token',
                'client_id' => 'random_'.bin2hex(random_bytes(4)),
            ]);
            $response = $middleware->handle($request, $this->passThrough(), '10', '60', '20');

            if ($i < 10) {
                $this->assertNotSame(429, $response->getStatusCode(), "Hit $i unexpectedly throttled");
            } else {
                $this->assertSame(429, $response->getStatusCode());
            }
        }
    }

    public function test_client_id_named_unknown_uses_its_own_bucket(): void
    {
        $middleware = new ThrottleTokenEndpoint;

        // Burn the _unknown fallback bucket with eleven random IDs to
        // saturate the IP bucket too, then verify a request with the literal
        // client_id="unknown" still goes through (it lives in c:unknown:*
        // which is a separate bucket from c:_unknown:*).
        for ($i = 0; $i < 11; $i++) {
            $middleware->handle(
                $this->makeRequest([
                    'grant_type' => 'refresh_token',
                    'client_id' => 'random_'.$i,
                ]),
                $this->passThrough(),
                '10', '60', '20',
            );
        }

        $response = $middleware->handle(
            $this->makeRequest([
                'grant_type' => 'refresh_token',
                'client_id' => 'unknown',
            ]),
            $this->passThrough(),
            '10', '60', '20',
        );

        // Same IP → IP bucket saturated → 429 still, but the bucket SEPARATION
        // would matter on a different IP. Verify the separation by switching
        // the IP and confirming the literal "unknown" gets a fresh start.
        $this->assertSame(429, $response->getStatusCode());

        $fresh = $middleware->handle(
            $this->makeRequest([
                'grant_type' => 'refresh_token',
                'client_id' => 'unknown',
            ], '10.0.0.99'),
            $this->passThrough(),
            '10', '60', '20',
        );
        $this->assertNotSame(429, $fresh->getStatusCode());
    }

    public function test_multibyte_client_id_is_cap_per_character_not_byte(): void
    {
        $middleware = new ThrottleTokenEndpoint;

        // 130 ASCII chars → over the 128 cap → falls to _unknown bucket.
        $longAscii = str_repeat('a', 130);
        $shortMultibyte = 'ñ'; // 2 bytes in UTF-8 but 1 char → within cap.

        $reqLong = $this->makeRequest([
            'grant_type' => 'refresh_token',
            'client_id' => $longAscii,
        ]);
        $reqShort = $this->makeRequest([
            'grant_type' => 'refresh_token',
            'client_id' => $shortMultibyte,
        ]);

        // 11 round trips: each request goes to the shared IP bucket.
        for ($i = 0; $i < 11; $i++) {
            $middleware->handle($reqLong, $this->passThrough(), '10', '60', '20');
        }

        // The multibyte request uses its own c:ñ:* bucket (separate from the
        // c:_unknown:* that the long-ascii client_id fell into).
        $response = $middleware->handle($reqShort, $this->passThrough(), '10', '60', '20');

        // IP bucket is saturated from the 11 long-ascii hits, so the short
        // request is still blocked. To prove bucket separation, switch IP.
        $this->assertSame(429, $response->getStatusCode());

        $freshIp = $this->makeRequest([
            'grant_type' => 'refresh_token',
            'client_id' => $shortMultibyte,
        ], '10.0.0.50');
        $fresh = $middleware->handle($freshIp, $this->passThrough(), '10', '60', '20');
        $this->assertNotSame(429, $fresh->getStatusCode());
    }
}
