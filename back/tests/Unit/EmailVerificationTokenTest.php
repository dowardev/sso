<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Unit;

use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationTokenTest extends TestCase
{
    use RefreshDatabase;

    private function makeToken(array $overrides = []): EmailVerificationToken
    {
        $user = User::factory()->unverified()->create();

        return EmailVerificationToken::create(array_merge([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'plain-token-'.uniqid()),
            'expires_at' => now()->addMinutes(30),
        ], $overrides));
    }

    public function test_pending_scope_excludes_used_revoked_and_expired(): void
    {
        $user = User::factory()->unverified()->create();
        $valid = EmailVerificationToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'valid'),
            'expires_at' => now()->addMinutes(30),
        ]);
        $used = EmailVerificationToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'used'),
            'expires_at' => now()->addMinutes(30),
            'used_at' => now(),
        ]);
        $revoked = EmailVerificationToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'revoked'),
            'expires_at' => now()->addMinutes(30),
            'revoked_at' => now(),
        ]);
        $expired = EmailVerificationToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'expired'),
            'expires_at' => now()->subMinute(),
        ]);

        $pendingIds = EmailVerificationToken::pending()->pluck('id')->all();

        $this->assertContains($valid->id, $pendingIds);
        $this->assertNotContains($used->id, $pendingIds);
        $this->assertNotContains($revoked->id, $pendingIds);
        $this->assertNotContains($expired->id, $pendingIds);
    }

    public function test_mark_as_used_sets_used_at(): void
    {
        $token = $this->makeToken();
        $token->markAsUsed();

        $this->assertNotNull($token->fresh()->used_at);
        $this->assertFalse($token->fresh()->isValid());
    }

    public function test_revoke_sets_revoked_at(): void
    {
        $token = $this->makeToken();
        $token->revoke();

        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertFalse($token->fresh()->isValid());
    }

    public function test_is_valid_requires_pending_and_future_expiry(): void
    {
        $this->assertTrue($this->makeToken()->isValid());
        $this->assertFalse($this->makeToken(['expires_at' => now()->subMinute()])->isValid());
        $this->assertFalse($this->makeToken(['used_at' => now()])->isValid());
        $this->assertFalse($this->makeToken(['revoked_at' => now()])->isValid());
    }

    public function test_user_relation_returns_owner(): void
    {
        $token = $this->makeToken();
        $this->assertInstanceOf(User::class, $token->user);
    }
}
