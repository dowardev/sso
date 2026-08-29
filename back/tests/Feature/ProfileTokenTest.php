<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTokenTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_profile_token_requires_authentication(): void
    {
        $this->getJson('/api/user/profile-token')->assertUnauthorized();
    }

    public function test_profile_token_returns_sso_signed_jws(): void
    {
        $user = $this->createAdvancedUser();

        $login = $this->postJson('/api/login/advanced/verify', [
            'login_id' => $user->login_id,
            'auth_proof' => 'auth-proof-secret',
        ])->assertOk();

        $token = $login->json('token');

        $response = $this->withToken($token)
            ->getJson('/api/user/profile-token')
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_in']);

        $jws = $response->json('token');
        $parts = explode('.', $jws);
        $this->assertCount(3, $parts, 'profile token must be a compact JWS');

        $payload = json_decode(
            base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]), true),
            true
        );

        $this->assertSame((string) $user->id, (string) ($payload['sub'] ?? null));
        $this->assertArrayHasKey('profile_version', $payload);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertGreaterThan(time(), $payload['exp']);
    }
}
