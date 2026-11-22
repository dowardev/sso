<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sliding-window rate limit dedicated to /oauth/token.
 *
 * Two buckets run in parallel — the most restrictive wins:
 *   - (client_id, IP): anti brute-force per client (e.g. refresh_token attacks
 *     against a known client).
 *   - (IP): anti enumeration. An attacker probing random `client_id` values
 *     hits a unique (client_id, IP) bucket each time but always shares the
 *     same IP bucket, so the limit still bites after a handful of attempts.
 *
 * `grant_type=authorization_code` is intentionally skipped: PKCE + the auth
 * code's own short TTL + single-use enforcement already protect that flow.
 *
 * Defense-in-depth: this middleware runs AFTER the generic `throttle:10,1` in
 * routes/api.php. In production the upstream already caps traffic at 10/min/IP
 * — the dedicated throttle adds per-client granularity on top of that ceiling.
 *
 * Algorithm per bucket: 2-bucket sliding window approximation.
 *   estimated = current_count + floor(previous_count * weight)
 *   weight    = (window - elapsed_in_current) / window
 *
 * `Retry-After` is a conservative upper bound (time until the current window
 * closes), not the optimal moment to retry once the previous window's weight
 * has decayed. Clients should not rely on it being exact.
 *
 * Drivers that support atomic Cache::increment (redis, database, memcached,
 * dynamodb, array) work out of the box. Production MUST use redis/database;
 * file-mode cache stores do not implement atomic increments and will degrade.
 */
class ThrottleTokenEndpoint
{
    private const DEFAULT_LIMIT = 10;

    private const DEFAULT_WINDOW_SECONDS = 60;

    private const DEFAULT_BURST = 20;

    private const MAX_CLIENT_ID_LENGTH = 128;

    private const KEY_PREFIX = 'oauth_token_throttle';

    public function handle(
        Request $request,
        Closure $next,
        ?string $limit = null,
        ?string $window = null,
        ?string $burst = null,
    ): Response {
        $maxAttempts = $limit !== null ? max(1, (int) $limit) : self::DEFAULT_LIMIT;
        $windowSeconds = $window !== null ? max(1, (int) $window) : self::DEFAULT_WINDOW_SECONDS;
        $burstLimit = $burst !== null ? max($maxAttempts, (int) $burst) : self::DEFAULT_BURST;

        if (strtolower((string) $request->input('grant_type', '')) === 'authorization_code') {
            return $next($request);
        }

        $ipBucket = $this->ipBucket($request);
        $clientBucket = $this->clientBucket($request);
        $now = time();
        $currentWindowIndex = (int) floor($now / $windowSeconds);
        $elapsedInWindow = $now % $windowSeconds;
        $weight = ($windowSeconds - $elapsedInWindow) / $windowSeconds;
        $retryAfter = $windowSeconds - $elapsedInWindow;

        $clientState = $this->bumpAndEstimate($clientBucket, $currentWindowIndex, $weight, $windowSeconds);
        $ipState = $this->bumpAndEstimate($ipBucket, $currentWindowIndex, $weight, $windowSeconds);

        if ($clientState['current'] > $burstLimit || $ipState['current'] > $burstLimit) {
            return $this->tooManyRequests($maxAttempts, $retryAfter);
        }

        if ($clientState['estimated'] > $maxAttempts || $ipState['estimated'] > $maxAttempts) {
            return $this->tooManyRequests($maxAttempts, $retryAfter);
        }

        $response = $next($request);

        $remaining = min(
            max(0, $maxAttempts - $clientState['estimated']),
            max(0, $maxAttempts - $ipState['estimated']),
        );
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);

        return $response;
    }

    /**
     * @return array{current:int, estimated:int}
     */
    private function bumpAndEstimate(string $bucket, int $currentWindowIndex, float $weight, int $windowSeconds): array
    {
        $currentKey = $bucket.':'.$currentWindowIndex;
        $previousKey = $bucket.':'.($currentWindowIndex - 1);

        $current = $this->incrementWithTtl($currentKey, $windowSeconds);
        $previous = (int) Cache::get($previousKey, 0);

        return [
            'current' => $current,
            'estimated' => $current + (int) floor($previous * $weight),
        ];
    }

    /**
     * Atomic increment with TTL set on first hit of the window.
     *
     * Order matters: `Cache::add()` first, then `Cache::increment()`. The
     * `add` resolves to `SET NX EX` on Redis drivers — it only sets the key
     * when it does not yet exist, with the TTL baked in, in a single atomic
     * round trip. On subsequent hits `add` is a no-op and the TTL survives.
     *
     * Drivers without atomic increment (e.g. file) return 0/false from
     * `increment`; we fall back to `put` and degrade gracefully (documented
     * in the class docblock).
     *
     * This pattern avoids the race where two concurrent first-hits both see
     * count=1 and one of them races a `Cache::put` that resets the counter.
     */
    private function incrementWithTtl(string $key, int $windowSeconds): int
    {
        Cache::add($key, 0, $windowSeconds * 2);

        $count = (int) Cache::increment($key);

        if ($count < 1) {
            Cache::put($key, 1, $windowSeconds * 2);

            return 1;
        }

        return $count;
    }

    private function ipBucket(Request $request): string
    {
        $ip = (string) ($request->ip() ?? '0.0.0.0');
        $ipKey = str_replace(['.', ':'], '_', $ip);

        return self::KEY_PREFIX.':ip:'.$ipKey;
    }

    private function clientBucket(Request $request): string
    {
        $rawClientId = trim((string) $request->input('client_id', ''));

        // Use mb_strlen so the byte cap is actually a character cap.
        if ($rawClientId === '' || mb_strlen($rawClientId, 'UTF-8') > self::MAX_CLIENT_ID_LENGTH) {
            $clientKey = '_unknown';
        } else {
            $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $rawClientId);
            $clientKey = $sanitized !== null && $sanitized !== '' ? $sanitized : '_unknown';
        }

        $ip = (string) ($request->ip() ?? '0.0.0.0');
        $ipKey = str_replace(['.', ':'], '_', $ip);

        return self::KEY_PREFIX.':c:'.$clientKey.':'.$ipKey;
    }

    private function tooManyRequests(int $limit, int $retryAfter): Response
    {
        return response()->json([
            'message' => 'Too many requests to the token endpoint. Please try again later.',
            'retry_after' => $retryAfter,
            'code' => ApiError::AUTH_TOO_MANY_ATTEMPTS,
        ], 429, [
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) $limit,
            'X-RateLimit-Remaining' => '0',
        ]);
    }
}
