<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Console\Commands;

use App\Services\EmailVerificationService;
use Illuminate\Console\Command;

class CleanupVerificationTokens extends Command
{
    protected $signature = 'verification-tokens:cleanup';

    protected $description = 'Delete expired email verification tokens';

    public function handle(EmailVerificationService $service): int
    {
        $deleted = $service->purgeExpired();

        $this->info("Purged {$deleted} expired verification token(s).");

        return self::SUCCESS;
    }
}