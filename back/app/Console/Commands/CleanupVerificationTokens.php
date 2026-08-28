<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Console\Commands;

use App\Services\EmailVerificationService;
use App\Services\PasswordResetService;
use Illuminate\Console\Command;

class CleanupVerificationTokens extends Command
{
    protected $signature = 'verification-tokens:cleanup';

    protected $description = 'Delete expired email verification and password reset tokens';

    public function handle(EmailVerificationService $service, PasswordResetService $passwordResetService): int
    {
        $verificationDeleted = $service->purgeExpired();
        $resetDeleted = $passwordResetService->purgeExpired();

        $this->info("Purged {$verificationDeleted} expired verification token(s) and {$resetDeleted} expired password reset token(s).");

        return self::SUCCESS;
    }
}