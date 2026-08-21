<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $emailVerificationService,
    ) {
    }

    public function verify(string $token): JsonResponse
    {
        $user = $this->emailVerificationService->verify($token);

        if (! $user) {
            return response()->json([
                'message' => 'Enlace de verificación inválido o expirado.',
            ], 400);
        }

        return response()->json([
            'message' => 'Email verificado correctamente.',
        ]);
    }

    public function resend(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email|max:255',
        ]);

        $this->emailVerificationService->resend($request->input('email'));

        // Generic 202 for both known and unknown emails (anti-enumeration).
        return response()->json([
            'message' => 'Si el email existe, recibirás un enlace de verificación.',
        ], 202);
    }
}