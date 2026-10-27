<?php

// Quick E2E-style test: advanced user deletes passkey with auth_proof re-auth.

namespace Tests\Feature;

use App\Models\User;
use App\Services\PasskeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Uid\Uuid;
use Tests\TestCase;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

class PasskeyDeleteAdvancedTest extends TestCase
{
    use RefreshDatabase;

    public function test_advanced_user_can_delete_passkey_with_auth_proof(): void
    {
        $saltA = base64_encode(random_bytes(32));
        $masterPassword = 'CorrectHorseBatteryStaple42';

        // Mirror the front: PBKDF2-HMAC-SHA256 (32 bytes) then HMAC-SHA256 key over "auth"
        $keyA = hash_pbkdf2('sha256', $masterPassword, base64_decode($saltA), 1_000_000, 64, true);
        $authProof = base64_encode(hash_hmac('sha256', 'auth', $keyA, true));

        $user = User::create([
            'name' => null,
            'user' => null,
            'email' => null,
            'password' => Hash::make($authProof),
            'email_verified_at' => now(),
            'login_id' => 'adv-login-id',
            'encryption_level' => 'advanced',
            'salt_a' => $saltA,
            'salt_r' => base64_encode(random_bytes(32)),
            'wrapped_dek_a' => json_encode(['wrapped' => 'x', 'algorithm' => 'AES-KW']),
            'wrapped_dek_r' => json_encode(['wrapped' => 'y', 'algorithm' => 'AES-KW']),
            'encrypted_profile' => json_encode(['ciphertext' => 'z', 'iv' => 'z', 'authTag' => 'z']),
            'encrypted_seed_verification' => 'v',
        ]);

        // Login FIRST (without passkey) to get a valid token
        $login = $this->postJson('/api/login/advanced/verify', [
            'login_id' => $user->login_id,
            'auth_proof' => $authProof,
        ]);
        $login->assertOk();
        $token = $login->json('token');

        // Now add a passkey via the service (bypassing registration ceremony)
        $service = app(PasskeyService::class);
        $record = CredentialRecord::create(
            random_bytes(16),
            'public-key',
            [],
            'none',
            new EmptyTrustPath,
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            random_bytes(32),
            (string) $user->id,
            0
        );
        $service->storeCredential($user, $record, []);
        $credentialId = $service->getCredentialIds($user)[0];

        // Delete the passkey with auth_proof re-auth
        $delete = $this->withToken($token)->deleteJson('/api/passkey', [
            'credential_id' => $credentialId,
            'auth_proof' => $authProof,
        ]);

        $delete->assertOk();
        $this->assertSame([], $service->getCredentialIds($user->fresh()));
    }
}
