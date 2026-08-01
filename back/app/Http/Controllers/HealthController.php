<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Models\CspReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $lastScheduleRun = Cache::get('schedule_last_run', null);

        $healthData = Cache::remember('health_metrics', 60, function () {
            return [
                'loginAttemptsCount' => DB::table('login_attempts')
                    ->where(function ($q) {
                        $q->whereNull('locked_until')
                            ->orWhere('locked_until', '>', now());
                    })
                    ->count(),

                'dpopNoncesCount' => DB::table('dpop_nonces')
                    ->where('expires_at', '>', now())
                    ->count(),

                'pendingLoginAttempts' => DB::table('login_attempts')
                    ->where('updated_at', '<', now()->subDays(1))
                    ->count(),

                'pendingDpopNonces' => DB::table('dpop_nonces')
                    ->where('expires_at', '<', now())
                    ->count(),

                'pendingRefreshTokens' => DB::table('user_refresh_tokens')
                    ->where('expires_at', '<', now())
                    ->orWhere('revoked_at', '<', now()->subDays(30))
                    ->count(),

                'activeSessions' => DB::table('user_sessions')->count(),

                'cspReportCount' => CspReport::count(),
                'cspReportToday' => CspReport::whereDate('created_at', today())->count(),
            ];
        });

        return response()->json([
            'cron_configured' => $lastScheduleRun !== null,
            'last_schedule_run' => $lastScheduleRun,
            'login_attempts_pending' => $healthData['loginAttemptsCount'],
            'dpop_nonces_pending' => $healthData['dpopNoncesCount'],
            'needs_cleanup' => [
                'login_attempts' => $healthData['pendingLoginAttempts'],
                'dpop_nonces' => $healthData['pendingDpopNonces'],
                'refresh_tokens' => $healthData['pendingRefreshTokens'],
            ],
            'active_sessions' => $healthData['activeSessions'],
            'csp_reports' => [
                'total' => $healthData['cspReportCount'],
                'today' => $healthData['cspReportToday'],
            ],
        ]);
    }

    public function cspReports(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);
        $status = $request->get('status');

        $query = CspReport::query();

        if ($status === 'pending') {
            $query->whereNull('resolved_at');
        } elseif ($status === 'resolved') {
            $query->whereNotNull('resolved_at');
        }

        $reports = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $reports->items(),
            'current_page' => $reports->currentPage(),
            'last_page' => $reports->lastPage(),
            'per_page' => $reports->perPage(),
            'total' => $reports->total(),
        ]);
    }

    public function allowScript(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hash' => 'required|string|regex:/^sha256-[A-Za-z0-9+\/=_-]+$/',
            'report_id' => 'nullable|integer|exists:csp_reports,id',
        ]);

        $configPath = config_path('csp.php');
        $config = require $configPath;

        $hash = $validated['hash'];

        if (in_array($hash, $config['script_hashes'], true)) {
            return response()->json([
                'message' => 'El hash ya está en la lista de permitidos',
            ], 409);
        }

        $config['script_hashes'][] = $hash;

        $content = '<?php'."\n\nreturn ".var_export($config, true).";\n";
        file_put_contents($configPath, $content);

        config(['csp.script_hashes' => $config['script_hashes']]);

        if (isset($validated['report_id'])) {
            CspReport::where('id', $validated['report_id'])->update([
                'resolved_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Hash agregado al CSP. Requiere deploy para producción.',
            'hash' => $hash,
        ]);
    }
}
