<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailVerificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_verify_returns_ok(): void
    {
        $user = User::factory()->unverified()->create();
        $plain = app(EmailVerificationService::class)->createForUser($user);

        $this->getJson('/api/email/verify/'.$plain)
            ->assertOk()
            ->assertJson(['message' => 'Email verified successfully.']);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verify_returns_400_for_invalid_token(): void
    {
        $this->getJson('/api/email/verify/not-a-real-token')
            ->assertStatus(400)
            ->assertJson(['message' => 'The verification link is invalid or expired.']);
    }

    public function test_resend_returns_generic_202_for_known_email(): void
    {
        $user = User::factory()->unverified()->create();
        app(EmailVerificationService::class)->createForUser($user);

        $this->postJson('/api/resend-confirmation-email', ['email' => $user->email])
            ->assertStatus(202)
            ->assertJson(['message' => 'If the email exists, you will receive a verification link.']);
    }

    public function test_resend_returns_same_202_for_unknown_email(): void
    {
        $this->postJson('/api/resend-confirmation-email', ['email' => 'nobody@example.com'])
            ->assertStatus(202)
            ->assertJson(['message' => 'If the email exists, you will receive a verification link.']);
    }

    public function test_resend_requires_email(): void
    {
        $this->postJson('/api/resend-confirmation-email', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
