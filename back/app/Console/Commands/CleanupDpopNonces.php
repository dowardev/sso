<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDpopNonces extends Command
{
    protected $signature = 'dpop:cleanup';
    protected $description = 'Delete expired DPoP nonces';

    public function handle(): void
    {
        $deleted = DB::table('dpop_nonces')
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("Deleted {$deleted} expired DPoP nonces.");
    }
}
