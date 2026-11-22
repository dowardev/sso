<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Services;

use App\Mail\ResetPassword;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordResetService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * Send a password reset link to a basic (email-backed) user.
     *
     * Always returns without throwing: if the identifier matches no basic user
     * (or belongs to a ZKE advanced user whose email is null) we silently do
     * nothing so the endpoint cannot be used to enumerate accounts.
     */
    public function sendResetLink(string $identifier): void
    {
        $user = User::where(function ($query) use ($identifier) {
            $query->where('email', $identifier)
                ->orWhere('user', $identifier);
        })
            ->whereNotNull('email')
            ->first();

        if (! $user) {
            return;
        }

        // Revoke previous pending tokens so only the newest link works.
        PasswordResetToken::where('user_id', $user->id)
            ->pending()
            ->update(['revoked_at' => now()]);

        $plain = Str::random(64);
        PasswordResetToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes(60),
        ]);

        Mail::to($user)->send(new ResetPassword($plain));
    }

    /**
     * Reset the password for the user behind a valid reset token.
     *
     * Returns the affected user, or null when the token is missing, used,
     * revoked, or expired.
     */
    public function reset(string $token, string $password): ?User
    {
        $record = PasswordResetToken::where('token_hash', hash('sha256', $token))
            ->pending()
            ->with('user')
            ->first();

        if (! $record || ! $record->user) {
            return null;
        }

        $user = $record->user;
        $user->password = Hash::make($password);
        $user->save();

        $record->markAsUsed();

        // Invalidate any other still-pending tokens for this user.
        PasswordResetToken::where('user_id', $user->id)
            ->pending()
            ->where('id', '!=', $record->id)
            ->update(['revoked_at' => now()]);

        $this->activityLogService->log(
            $user,
            UserActivityLog::ACTION_PASSWORD_CHANGE,
            'Password reset (email)',
            ['reset' => true],
        );

        return $user;
    }

    public function purgeExpired(): int
    {
        return PasswordResetToken::where('expires_at', '<', now())->delete();
    }
}
