<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Models\CspReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CspReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->all();

        $cspReport = $data['csp-report'] ?? $data;

        CspReport::create([
            'blocked_uri' => $cspReport['blocked-uri'] ?? null,
            'document_uri' => $cspReport['document-uri'] ?? null,
            'referrer' => $cspReport['referrer'] ?? null,
            'violated_directive' => $cspReport['violated-directive'] ?? null,
            'effective_directive' => $cspReport['effective-directive'] ?? null,
            'original_policy' => $cspReport['original-policy'] ?? null,
            'source_file' => $cspReport['source-file'] ?? null,
            'line_number' => $cspReport['line-number'] ?? null,
            'script_sample' => isset($cspReport['script-sample'])
                ? substr($cspReport['script-sample'], 0, 512)
                : null,
        ]);

        Log::warning('CSP Violation', [
            'blocked_uri' => $cspReport['blocked-uri'] ?? null,
            'violated_directive' => $cspReport['violated-directive'] ?? null,
            'document_uri' => $cspReport['document-uri'] ?? null,
        ]);

        return response()->json(['status' => 'ok']);
    }
}
