<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Models\CspReport;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CspReportController extends Controller
{
    public function index(Request $request): JsonResponse
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
            'current_page' => $reports->current_page,
            'last_page' => $reports->last_page,
            'per_page' => $reports->per_page,
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
            return response()->json(
                ApiError::topLevel(ApiError::CSP_HASH_ALREADY_ALLOWED, 'The hash is already in the allowed list.'),
                409
            );
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

        return response()->json(
            ApiError::success(ApiError::SUCCESS_CSP_HASH_ADDED, 'Hash added to the CSP. A production deploy is required.') + [
                'hash' => $hash,
            ]
        );
    }
}
