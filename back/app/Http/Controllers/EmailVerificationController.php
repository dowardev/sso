<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Services\EmailVerificationService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $emailVerificationService,
    ) {}

    public function verify(string $token): JsonResponse
    {
        $user = $this->emailVerificationService->verify($token);

        if (! $user) {
            return response()->json(
                ApiError::topLevel(ApiError::EMAIL_VERIFICATION_INVALID_LINK, 'The verification link is invalid or expired.'),
                400
            );
        }

        return response()->json(ApiError::success(ApiError::SUCCESS_EMAIL_VERIFIED, 'Email verified successfully.'));
    }

    public function resend(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email|max:255',
        ]);

        $this->emailVerificationService->resend($request->input('email'));

        // Generic 202 for both known and unknown emails (anti-enumeration).
        return response()->json(
            ApiError::success(ApiError::SUCCESS_VERIFICATION_EMAIL_RESENT, 'If the email exists, you will receive a verification link.'),
            202
        );
    }
}
