<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ThrottleLoginAttempts
{
    public function handle(Request $request, Closure $next): Response
    {
        $identifier = $request->input('email') ?? $request->input('user') ?? '';
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
                ], 429);
            }
        }

        $response = $next($request);

        if ($response->getStatusCode() === 401 && $identifier) {
            DB::statement("
                INSERT INTO login_attempts (identifier, ip, attempts, locked_until, created_at, updated_at)
                VALUES (?, ?, 1, NULL, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    attempts = IF(locked_until IS NOT NULL AND locked_until > NOW(), attempts, attempts + 1),
                    locked_until = IF(
                        locked_until IS NOT NULL AND locked_until > NOW(),
                        locked_until,
                        IF(attempts + 1 >= 4, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NULL)
                    ),
                    updated_at = NOW()
            ", [$identifier, $ip]);
        }

        if ($response->getStatusCode() === 200 && $identifier) {
            DB::table('login_attempts')
                ->where('identifier', $identifier)
                ->where('ip', $ip)
                ->delete();
        }

        return $response;
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
