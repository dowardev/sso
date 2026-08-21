<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Mail\VerifyEmail;
use App\Models\SsoClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegisterEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeClient(): SsoClient
    {
        return SsoClient::factory()->create();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New User',
            'user' => 'new_user',
            'email' => 'new@example.com',
            'password' => 'StrongP@ss1',
            'password_confirmation' => 'StrongP@ss1',
            'client_id' => $this->makeClient()->client_id,
        ], $overrides);
    }

    public function test_register_sends_verification_mail_and_issues_no_token(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/register', $this->validPayload());

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Revisa tu email',
                'user' => ['email' => 'new@example.com'],
            ])
            ->assertJsonMissing(['token']);

        $user = User::where('email', 'new@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        Mail::assertSent(VerifyEmail::class);
        $this->assertDatabaseCount('user_sessions', 0);
    }
}