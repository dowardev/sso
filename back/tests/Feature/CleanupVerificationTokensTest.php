<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CleanupVerificationTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_purges_expired_tokens(): void
    {
        $user = User::factory()->unverified()->create();
        EmailVerificationToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'expired'),
            'expires_at' => now()->subMinute(),
        ]);
        EmailVerificationToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'valid'),
            'expires_at' => now()->addMinutes(30),
        ]);

        Artisan::call('verification-tokens:cleanup');

        $this->assertSame(1, EmailVerificationToken::count());
    }
}