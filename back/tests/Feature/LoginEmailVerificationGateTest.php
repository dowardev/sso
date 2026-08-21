<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginEmailVerificationGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_cannot_login(): void
    {
        User::factory()->unverified()->create([
            'email' => 'unverified@example.com',
            'password' => bcrypt('StrongP@ss1'),
        ]);

        $this->postJson('/api/login', [
            'user' => 'unverified@example.com',
            'password' => 'StrongP@ss1',
        ])
            ->assertStatus(403)
            ->assertJson([
                'code' => 'EMAIL_NOT_VERIFIED',
                'message' => 'Verifica tu email antes de iniciar sesión.',
                'email' => 'unverified@example.com',
            ])
            ->assertJsonMissing(['token']);
    }

    public function test_verified_user_can_login(): void
    {
        User::factory()->create([
            'email' => 'verified@example.com',
            'password' => bcrypt('StrongP@ss1'),
        ]);

        $this->postJson('/api/login', [
            'user' => 'verified@example.com',
            'password' => 'StrongP@ss1',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'verified@example.com')
            ->assertJsonStructure(['token']);
    }

    public function test_invalid_credentials_still_return_401(): void
    {
        User::factory()->unverified()->create([
            'email' => 'unverified@example.com',
            'password' => bcrypt('StrongP@ss1'),
        ]);

        $this->postJson('/api/login', [
            'user' => 'unverified@example.com',
            'password' => 'WrongP@ss1',
        ])
            ->assertStatus(401);
    }
}
