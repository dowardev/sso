<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ThrottleLoginAttempts
{
    public function handle(Request $request, Closure $next): Response
    {
        $identifier = $request->input('email') ?? $request->input('user') ?? $request->input('login_id') ?? '';
        $ip = $request->ip();

        $this->lazyCleanup();

        if ($identifier) {
            $attempt = DB::table('login_attempts')
                ->where('identifier', $identifier)
                ->where('ip', $ip)
                ->first();

            if ($attempt && $attempt->locked_until && now()->lessThan($attempt->locked_until)) {
                return response()->json([
                    'message' => 'Demasiados intentos. Cuenta bloqueada temporalmente.',
                    'locked_until' => $attempt->locked_until,
                    'code' => ApiError::AUTH_TOO_MANY_ATTEMPTS,
                ], 429);
            }
        }

        $response = $next($request);

        if ($response->getStatusCode() === 401 && $identifier) {
            $this->recordFailedAttempt($identifier, $ip);
        }

        if ($response->getStatusCode() === 200 && $identifier) {
            DB::table('login_attempts')
                ->where('identifier', $identifier)
                ->where('ip', $ip)
                ->delete();
        }

        return $response;
    }

    /**
     * Portable upsert of a failed login attempt (works on MySQL and SQLite).
     *
     * Rules (same as the previous MySQL-only statement):
     * - no row yet: insert with attempts = 1, no lock;
     * - row exists and currently locked: keep counters, only touch updated_at;
     * - row exists and not locked: attempts + 1, lock for 15 minutes from the 4th attempt on.
     */
    private function recordFailedAttempt(string $identifier, ?string $ip): void
    {
        $now = now();

        $attempt = DB::table('login_attempts')
            ->where('identifier', $identifier)
            ->where('ip', $ip)
            ->first();

        if ($attempt === null) {
            DB::table('login_attempts')->insert([
                'identifier' => $identifier,
                'ip' => $ip,
                'attempts' => 1,
                'locked_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        if ($attempt->locked_until && $now->lessThan($attempt->locked_until)) {
            DB::table('login_attempts')
                ->where('identifier', $identifier)
                ->where('ip', $ip)
                ->update(['updated_at' => $now]);

            return;
        }

        $attempts = ((int) $attempt->attempts) + 1;

        DB::table('login_attempts')
            ->where('identifier', $identifier)
            ->where('ip', $ip)
            ->update([
                'attempts' => $attempts,
                'locked_until' => $attempts >= 4 ? $now->copy()->addMinutes(15) : null,
                'updated_at' => $now,
            ]);
    }

    private function lazyCleanup(): void
    {
        $lastCleanup = Cache::get('login_attempts_cleanup_date', 0);

        if ($lastCleanup < now()->startOfDay()->timestamp) {
            DB::table('login_attempts')
                ->where('locked_until', '<', now())
                ->orWhere('updated_at', '<', now()->subDays(7))
                ->limit(1000)
                ->delete();

            Cache::put('login_attempts_cleanup_date', now()->startOfDay()->timestamp, 86400);
        }
    }
}
