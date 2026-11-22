<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Services;

use App\Mail\VerifyEmail;
use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmailVerificationService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function createForUser(User $user): string
    {
        // Revoke previous pending tokens so only the newest link works.
        EmailVerificationToken::where('user_id', $user->id)
            ->pending()
            ->update(['revoked_at' => now()]);

        $plain = Str::random(64);
        EmailVerificationToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes(30),
        ]);

        Mail::to($user)->send(new VerifyEmail($plain));

        return $plain;
    }

    public function verify(string $plainToken): ?User
    {
        $token = EmailVerificationToken::where('token_hash', hash('sha256', $plainToken))
            ->pending()
            ->with('user')
            ->first();

        if (! $token || ! $token->user) {
            return null;
        }

        $token->markAsUsed();

        $user = $token->user;
        $user->email_verified_at = now();
        $user->save();

        $this->activityLogService->log(
            $user,
            UserActivityLog::ACTION_EMAIL_VERIFIED,
            'Email verified',
            ['email' => $user->email],
        );

        return $user;
    }

    public function resend(string $email): void
    {
        $user = User::where('email', $email)->first();

        if ($user && $user->email_verified_at === null) {
            $this->createForUser($user);
        }
    }

    public function purgeExpired(): int
    {
        return EmailVerificationToken::where('expires_at', '<', now())->delete();
    }
}
