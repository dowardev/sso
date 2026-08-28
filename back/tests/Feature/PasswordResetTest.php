<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Mail\ResetPassword;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function makeBasicUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'user' => 'alice',
            'email' => 'alice@example.com',
            'password' => bcrypt('OldP@ss1'),
        ], $attrs));
    }

    public function test_forgot_password_sends_email_for_known_user(): void
    {
        Mail::fake();
        $this->makeBasicUser();

        $this->postJson('/api/password/forgot', ['identifier' => 'alice'])
            ->assertOk();

        Mail::assertSent(ResetPassword::class);
    }

    public function test_forgot_password_is_silent_for_unknown_identifier(): void
    {
        Mail::fake();

        $this->postJson('/api/password/forgot', ['identifier' => 'ghost'])
            ->assertOk();

        Mail::assertNothingSent();
    }

    public function test_reset_rejects_weak_password_and_accepts_strong(): void
    {
        $user = $this->makeBasicUser();
        $token = Str::random(64);
        PasswordResetToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(60),
        ]);

        // Weak password (too short, no complexity) must be rejected by policy.
        $this->postJson('/api/password/reset', [
            'token' => $token,
            'password' => 'shorty',
            'password_confirmation' => 'shorty',
        ])->assertStatus(422);

        // Strong password (min:10 + complexity) succeeds.
        $this->postJson('/api/password/reset', [
            'token' => $token,
            'password' => 'NewStr0ng!',
            'password_confirmation' => 'NewStr0ng!',
        ])->assertOk();
    }

    public function test_reset_is_single_use(): void
    {
        $user = $this->makeBasicUser();
        $token = Str::random(64);
        PasswordResetToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(60),
        ]);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'password' => 'NewStr0ng!',
            'password_confirmation' => 'NewStr0ng!',
        ])->assertOk();

        // Same token reused after being consumed -> 422.
        $this->postJson('/api/password/reset', [
            'token' => $token,
            'password' => 'An0ther!Pass',
            'password_confirmation' => 'An0ther!Pass',
        ])->assertStatus(422);
    }

    public function test_reset_rejects_expired_token(): void
    {
        $user = $this->makeBasicUser();
        $token = Str::random(64);
        PasswordResetToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subMinutes(5),
        ]);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'password' => 'NewStr0ng!',
            'password_confirmation' => 'NewStr0ng!',
        ])->assertStatus(422);
    }
}
