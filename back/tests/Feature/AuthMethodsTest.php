<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthMethodsTest extends TestCase
{
    use RefreshDatabase;

    public function test_finds_basic_user_by_username(): void
    {
        User::factory()->create([
            'user' => 'basicname',
            'encryption_level' => 'basic',
        ]);

        $response = $this->postJson('/api/auth/methods', ['identifier' => 'basicname']);

        $response->assertOk()->assertExactJson([
            'account_found' => true,
            'encryption_level' => 'basic',
            'has_passkeys' => false,
            'login_id' => null,
        ]);
    }

    public function test_email_is_not_a_valid_identifier(): void
    {
        User::factory()->create([
            'email' => 'basic@example.com',
            'encryption_level' => 'basic',
        ]);

        $response = $this->postJson('/api/auth/methods', ['identifier' => 'basic@example.com']);

        $response->assertOk()->assertExactJson([
            'account_found' => false,
            'encryption_level' => null,
            'has_passkeys' => false,
            'login_id' => null,
        ]);
    }

    public function test_finds_advanced_user_via_hmac_login_id(): void
    {
        $identifier = 'advanced_user';
        $loginId = base64_encode(hash_hmac('sha256', $identifier, 'login_id_salt_v1', true));

        User::factory()->create([
            'email' => null,
            'user' => null,
            'login_id' => $loginId,
            'encryption_level' => 'advanced',
        ]);

        $response = $this->postJson('/api/auth/methods', ['identifier' => $identifier]);

        $response->assertOk()->assertExactJson([
            'account_found' => true,
            'encryption_level' => 'advanced',
            'has_passkeys' => false,
            'login_id' => $loginId,
        ]);
    }

    public function test_raw_login_id_is_not_a_valid_identifier(): void
    {
        $loginId = base64_encode(hash_hmac('sha256', 'alice', 'login_id_salt_v1', true));

        User::factory()->create([
            'email' => null,
            'user' => null,
            'login_id' => $loginId,
            'encryption_level' => 'advanced',
        ]);

        $response = $this->postJson('/api/auth/methods', ['identifier' => $loginId]);

        $response->assertOk()->assertExactJson([
            'account_found' => false,
            'encryption_level' => null,
            'has_passkeys' => false,
            'login_id' => null,
        ]);
    }

    public function test_reports_has_passkeys_when_passkey_credential_exists(): void
    {
        $user = User::factory()->create([
            'user' => 'withpasskey',
            'encryption_level' => 'basic',
        ]);

        PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('credential-1'),
            'public_key' => 'fake-public-key',
            'user_handle' => (string) $user->id,
        ]);

        $response = $this->postJson('/api/auth/methods', ['identifier' => 'withpasskey']);

        $response->assertOk()->assertExactJson([
            'account_found' => true,
            'encryption_level' => 'basic',
            'has_passkeys' => true,
            'login_id' => null,
        ]);
    }

    public function test_reports_advanced_user_with_passkeys(): void
    {
        $identifier = 'advanced_with_passkey';
        $loginId = base64_encode(hash_hmac('sha256', $identifier, 'login_id_salt_v1', true));

        $user = User::factory()->create([
            'email' => null,
            'user' => null,
            'login_id' => $loginId,
            'encryption_level' => 'advanced',
        ]);

        PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('credential-adv-1'),
            'public_key' => 'fake-public-key',
            'user_handle' => (string) $user->id,
        ]);

        $response = $this->postJson('/api/auth/methods', ['identifier' => $identifier]);

        $response->assertOk()->assertExactJson([
            'account_found' => true,
            'encryption_level' => 'advanced',
            'has_passkeys' => true,
            'login_id' => $loginId,
        ]);
    }

    public function test_unknown_identifier_returns_account_not_found_with_200(): void
    {
        $response = $this->postJson('/api/auth/methods', ['identifier' => 'nobody']);

        $response->assertOk()->assertExactJson([
            'account_found' => false,
            'encryption_level' => null,
            'has_passkeys' => false,
            'login_id' => null,
        ]);
    }

    public function test_identifier_is_required(): void
    {
        $response = $this->postJson('/api/auth/methods', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['identifier']);
    }
}
