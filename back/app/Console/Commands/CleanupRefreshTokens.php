<?php

namespace App\Console\Commands;

use App\Models\UserRefreshToken;
use Illuminate\Console\Command;

class CleanupRefreshTokens extends Command
{
    protected $signature = 'tokens:cleanup';
    protected $description = 'Delete expired or revoked refresh tokens older than 30 days';

    public function handle(): void
    {
        $deleted = UserRefreshToken::where('expires_at', '<', now())
            ->orWhere('revoked_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Deleted {$deleted} expired/revoked refresh tokens.");
    }
}
