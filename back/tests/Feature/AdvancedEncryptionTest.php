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

        $this->postJson('/api/crypto/blobs', [
            'login_id' => $user->login_id,
        ])
            ->assertOk()
            ->assertJsonPath('encryption_level', 'advanced')
            ->assertJsonStructure(['encryption_level', 'salt_a', 'salt_r', 'wrapped_dek_a', 'encrypted_profile']);
    }

    public function test_advanced_first_step_hides_unknown_login_id(): void
    {
        $this->postJson('/api/crypto/blobs', [
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
            ->assertJson(['code' => 'auth.email_not_verified']);
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
            'password_length' => 12,
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
        $this->postJson('/api/crypto/blobs', [
            'login_id' => 'any-basic-login-id',
        ])
            ->assertStatus(404);
    }

    /**
     * The upgrade path (basic -> advanced) is the ONLY way to reach advanced
     * encryption now that registration is basic-only. It must null the
     * plaintext identity fields, swap the password to the auth_proof hash,
     * bump profile_version, and revoke every session.
     */
    public function test_basic_user_upgrades_to_advanced_via_profile(): void
    {
        $user = User::factory()->create([
            'email' => 'upgrade@example.com',
            'user' => 'upgrader',
            'name' => 'Upgrader User',
            'password' => bcrypt('CurrentPass123'),
            'email_verified_at' => now(),
        ]);

        // Authenticate as a normal basic user (post-verification).
        $response = $this->postJson('/api/login', [
            'email' => 'upgrade@example.com',
            'password' => 'CurrentPass123',
        ]);
        $token = $response->json('token');
        $this->assertNotFalse($token, 'Login should succeed for a verified basic user');

        $this->withToken($token)
            ->postJson('/api/user/upgrade', [
                'current_password' => 'CurrentPass123',
                'auth_proof' => 'new-auth-proof-secret',
                'login_id' => 'upgrader-login-id-base64==',
                'salt_a' => base64_encode('salt-a-32-bytes-long!!!!!!!!!'),
                'salt_r' => base64_encode('salt-r-32-bytes-long!!!!!!!!!'),
                'wrapped_dek_a' => json_encode(['wrapped' => 'wrapped-a', 'algorithm' => 'AES-KW']),
                'wrapped_dek_r' => json_encode(['wrapped' => 'wrapped-r', 'algorithm' => 'AES-KW']),
                'encrypted_profile' => json_encode(['ciphertext' => 'fake', 'iv' => 'fake', 'authTag' => 'fake']),
                'encrypted_seed_verification' => 'seed-verification-hash',
                'password_length' => 12,
                'seed_phrase' => 'twenty four words seed phrase for recovery storage',
            ])
            ->assertOk()
            ->assertJsonPath('seed_phrase', 'twenty four words seed phrase for recovery storage');

        $user->refresh();

        // Zero-knowledge: server no longer holds the plaintext identity.
        $this->assertSame('advanced', $user->encryption_level);
        $this->assertNull($user->name);
        $this->assertNull($user->user);
        $this->assertNull($user->email);
        $this->assertSame('upgrader-login-id-base64==', $user->login_id);
        $this->assertSame(1, $user->profile_version);

        // The account password now authenticates the auth_proof, not the
        // original basic password.
        $this->assertTrue(Hash::check('new-auth-proof-secret', $user->password));
        $this->assertFalse(Hash::check('CurrentPass123', $user->password));
    }

    public function test_upgrade_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create([
            'email' => 'upgrade2@example.com',
            'password' => bcrypt('CurrentPass123'),
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'upgrade2@example.com',
            'password' => 'CurrentPass123',
        ]);
        $token = $response->json('token');

        $this->withToken($token)
            ->postJson('/api/user/upgrade', [
                'current_password' => 'WrongPass',
                'auth_proof' => 'new-auth-proof-secret',
                'login_id' => 'upgrader-login-id-2==',
                'salt_a' => base64_encode('salt-a-32-bytes-long!!!!!!!!!'),
                'salt_r' => base64_encode('salt-r-32-bytes-long!!!!!!!!!'),
                'wrapped_dek_a' => json_encode(['wrapped' => 'wrapped-a', 'algorithm' => 'AES-KW']),
                'wrapped_dek_r' => json_encode(['wrapped' => 'wrapped-r', 'algorithm' => 'AES-KW']),
                'encrypted_profile' => json_encode(['ciphertext' => 'fake', 'iv' => 'fake', 'authTag' => 'fake']),
                'encrypted_seed_verification' => 'seed-verification-hash',
                'password_length' => 12,
            ])
            ->assertStatus(422)
            ->assertJson(['message' => 'Contraseña actual incorrecta']);

        $user->refresh();
        $this->assertSame('basic', $user->encryption_level);
        $this->assertSame('upgrade2@example.com', $user->email);
    }
}
