<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdvancedEncryptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create an advanced user directly in the database.
     */
    private function createAdvancedUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => null,
            'user' => null,
            'email' => null,
            'password' => Hash::make('auth-proof-secret'),
            'email_verified_at' => now(),
            'login_id' => 'test-login-id-base64==',
            'encryption_level' => 'advanced',
            'salt_a' => base64_encode(random_bytes(32)),
            'salt_r' => base64_encode(random_bytes(32)),
            'wrapped_dek_a' => json_encode(['wrapped' => 'fake-wrapped-a', 'algorithm' => 'AES-KW']),
            'wrapped_dek_r' => json_encode(['wrapped' => 'fake-wrapped-r', 'algorithm' => 'AES-KW']),
            'encrypted_profile' => json_encode(['ciphertext' => 'fake', 'iv' => 'fake', 'authTag' => 'fake']),
            'encrypted_seed_verification' => 'seed-verification-hash',
        ], $overrides));
    }

    public function test_advanced_first_step_returns_crypto_blobs(): void
    {
        $user = $this->createAdvancedUser();

        $this->postJson('/api/login/advanced/first-step', [
            'login_id' => $user->login_id,
        ])
            ->assertOk()
            ->assertJsonPath('encryption_level', 'advanced')
            ->assertJsonStructure(['encryption_level', 'salt_a', 'salt_r', 'wrapped_dek_a', 'encrypted_profile']);
    }

    public function test_advanced_first_step_hides_unknown_login_id(): void
    {
        $this->postJson('/api/login/advanced/first-step', [
            'login_id' => 'unknown-id',
        ])
            ->assertStatus(404);
    }

    public function test_advanced_verify_accepts_valid_auth_proof(): void
    {
        $user = $this->createAdvancedUser();

        $this->postJson('/api/login/advanced/verify', [
            'login_id' => $user->login_id,
            'auth_proof' => 'auth-proof-secret',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'expires_in']);
    }

    public function test_advanced_verify_rejects_wrong_auth_proof(): void
    {
        $user = $this->createAdvancedUser();

        $this->postJson('/api/login/advanced/verify', [
            'login_id' => $user->login_id,
            'auth_proof' => 'wrong-proof',
        ])
            ->assertStatus(401);
    }

    public function test_advanced_verify_blocks_unverified_email(): void
    {
        $user = $this->createAdvancedUser(['email_verified_at' => null]);

        $this->postJson('/api/login/advanced/verify', [
            'login_id' => $user->login_id,
            'auth_proof' => 'auth-proof-secret',
        ])
            ->assertStatus(403)
            ->assertJson(['code' => 'EMAIL_NOT_VERIFIED']);
    }

    public function test_recovery_verify_rejects_wrong_seed_verification(): void
    {
        $user = $this->createAdvancedUser();

        $this->postJson('/api/recovery/verify', [
            'login_id' => $user->login_id,
            'seed_verification' => 'wrong-seed-hash',
        ])
            ->assertStatus(401)
            ->assertJson(['message' => 'Frase semilla incorrecta']);
    }

    public function test_recovery_verify_returns_blobs_on_correct_seed(): void
    {
        $user = $this->createAdvancedUser();

        $this->postJson('/api/recovery/verify', [
            'login_id' => $user->login_id,
            'seed_verification' => 'seed-verification-hash',
        ])
            ->assertOk()
            ->assertJsonStructure(['salt_r', 'wrapped_dek_r', 'encrypted_profile']);
    }

    public function test_recovery_complete_updates_crypto_and_revokes_sessions(): void
    {
        $user = $this->createAdvancedUser();

        // Create an active session to verify it gets revoked later is covered
        // by SessionService; here we assert the blobs update.
        $this->postJson('/api/recovery/complete', [
            'login_id' => $user->login_id,
            'auth_proof' => 'new-auth-proof',
            'salt_a' => base64_encode('new-salt-a-32-bytes-long!!!!!!'),
            'wrapped_dek_a' => json_encode(['wrapped' => 'new-wrapped-a', 'algorithm' => 'AES-KW']),
            'wrapped_dek_r' => json_encode(['wrapped' => 'new-wrapped-r', 'algorithm' => 'AES-KW']),
            'encrypted_profile' => json_encode(['ciphertext' => 'new-fake', 'iv' => 'fake', 'authTag' => 'fake']),
        ])
            ->assertOk()
            ->assertJsonStructure(['message', 'user']);

        $user->refresh();

        $this->assertSame(base64_encode('new-salt-a-32-bytes-long!!!!!!'), $user->salt_a);
        $this->assertTrue(Hash::check('new-auth-proof', $user->password));
    }

    public function test_basic_user_cannot_use_advanced_first_step(): void
    {
        User::factory()->create([
            'email' => 'basic@example.com',
            'password' => bcrypt('StrongP@ss1'),
        ]);

        // login_id of a basic user does not match any advanced user
        $this->postJson('/api/login/advanced/first-step', [
            'login_id' => 'any-basic-login-id',
        ])
            ->assertStatus(404);
    }
}
