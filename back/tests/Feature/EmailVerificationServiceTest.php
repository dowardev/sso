<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Mail\VerifyEmail;
use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmailVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EmailVerificationService::class);
        Mail::fake();
    }

    public function test_create_for_user_revokes_previous_and_sends_mail(): void
    {
        $user = User::factory()->unverified()->create();
        $first = $this->service->createForUser($user);
        $second = $this->service->createForUser($user);

        $this->assertNotSame($first, $second);
        $this->assertSame(
            1,
            EmailVerificationToken::where('user_id', $user->id)->pending()->count()
        );
        $this->assertSame(
            1,
            EmailVerificationToken::where('user_id', $user->id)->whereNotNull('revoked_at')->count()
        );

        Mail::assertSent(VerifyEmail::class, function (VerifyEmail $mail) use ($second) {
            return $mail->verificationUrl === config('app.frontend_url').'/verify/'.$second;
        });
    }

    public function test_verify_marks_token_used_and_verifies_user(): void
    {
        $user = User::factory()->unverified()->create();
        $plain = $this->service->createForUser($user);

        $result = $this->service->verify($plain);

        $this->assertSame($user->id, $result->id);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNotNull(
            EmailVerificationToken::where('user_id', $user->id)->first()->fresh()->used_at
        );
        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $user->id,
            'action' => UserActivityLog::ACTION_EMAIL_VERIFIED,
        ]);
    }

    public function test_verify_rejects_unknown_token(): void
    {
        $this->assertNull($this->service->verify('unknown-token'));
    }

    public function test_verify_rejects_replay(): void
    {
        $user = User::factory()->unverified()->create();
        $plain = $this->service->createForUser($user);
        $this->service->verify($plain);

        $this->assertNull($this->service->verify($plain));
    }

    public function test_verify_rejects_revoked_token(): void
    {
        $user = User::factory()->unverified()->create();
        $plain = $this->service->createForUser($user);
        EmailVerificationToken::where('user_id', $user->id)->first()->revoke();

        $this->assertNull($this->service->verify($plain));
    }

    public function test_resend_creates_new_token_for_existing_unverified_user(): void
    {
        $user = User::factory()->unverified()->create();
        $this->service->createForUser($user);

        $this->service->resend($user->email);

        $this->assertSame(
            1,
            EmailVerificationToken::where('user_id', $user->id)->pending()->count()
        );
        Mail::assertSent(VerifyEmail::class, 2);
    }

    public function test_resend_does_nothing_for_unknown_email(): void
    {
        $this->service->resend('nobody@example.com');

        $this->assertDatabaseCount('email_verification_tokens', 0);
        Mail::assertNothingSent();
    }

    public function test_purge_expired_deletes_only_expired(): void
    {
        $user = User::factory()->unverified()->create();
        $expired = EmailVerificationToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'expired'),
            'expires_at' => now()->subMinute(),
        ]);
        EmailVerificationToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'valid'),
            'expires_at' => now()->addMinutes(30),
        ]);

        $count = $this->service->purgeExpired();

        $this->assertSame(1, $count);
        $this->assertDatabaseMissing('email_verification_tokens', ['id' => $expired->id]);
        $this->assertDatabaseCount('email_verification_tokens', 1);
    }
}