<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasskeyMultiCredentialTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::factory()->create([
            'email' => 'passkey@example.com',
            'password' => bcrypt('Password123'),
            'email_verified_at' => now(),
        ]);
    }

    public function test_register_start_emits_prf_extension(): void
    {
        $user = $this->makeUser();
        $service = app(\App\Services\PasskeyService::class);

        $options = $service->generateRegistrationOptions($user);
        $data = $service->serializeOptions($options);

        // PRF must be enabled so the authenticator exposes the DEK-wrap secret
        $this->assertTrue($data['extensions']['prf']['enable'] ?? false);
        // Options must carry a signed challenge and the ES256/RS256 algorithms
        $this->assertNotEmpty($data['challenge'] ?? '');
        $this->assertSame('public-key', $data['pubKeyCredParams'][0]['type'] ?? null);
    }

    public function test_options_round_trip_preserves_challenge(): void
    {
        $user = $this->makeUser();
        $service = app(\App\Services\PasskeyService::class);

        $options = $service->generateRegistrationOptions($user);
        $serialized = $service->serializeOptions($options);

        $rehydrated = $service->deserializeOptions($serialized, \Webauthn\PublicKeyCredentialCreationOptions::class);

        // The challenge survives serialization -> storage -> rehydration,
        // so the stored challenge is exactly what the client signs.
        $this->assertSame(
            $options->challenge,
            $rehydrated->challenge
        );
        $this->assertTrue($rehydrated->extensions->has('prf'));
    }

    public function test_service_stores_multiple_credentials_without_overwriting(): void
    {
        $user = $this->makeUser();
        $service = app(\App\Services\PasskeyService::class);

        $service->storeCredential($user, $this->fakeRecord('AAAA'), ['device_name' => 'Phone']);
        $service->storeCredential($user, $this->fakeRecord('BBBB'), ['device_name' => 'Key']);

        $this->assertSame([$this->b64('AAAA'), $this->b64('BBBB')], $service->getCredentialIds($user));
        $this->assertCount(2, $service->getUserCredentialRecords($user));
        $this->assertSame(2, PasskeyCredential::where('user_id', $user->id)->count());
    }

    public function test_service_removes_only_the_requested_credential(): void
    {
        $user = $this->makeUser();
        $service = app(\App\Services\PasskeyService::class);

        $service->storeCredential($user, $this->fakeRecord('AAAA'));
        $service->storeCredential($user, $this->fakeRecord('BBBB'));

        $service->removeCredential($user, $this->b64('AAAA'));

        $this->assertSame([$this->b64('BBBB')], $service->getCredentialIds($user));
        $this->assertSame(1, PasskeyCredential::where('user_id', $user->id)->count());
    }

    public function test_delete_endpoint_removes_a_single_credential_by_id(): void
    {
        $user = $this->makeUser();
        $service = app(\App\Services\PasskeyService::class);
        $service->storeCredential($user, $this->fakeRecord('AAAA'));
        $service->storeCredential($user, $this->fakeRecord('BBBB'));

        $token = $this->loginToken($user);

        $this->withToken($token)
            ->deleteJson('/api/passkey', ['credential_id' => $this->b64('AAAA')])
            ->assertOk();

        $this->assertSame([$this->b64('BBBB')], $service->getCredentialIds($user->fresh()));
    }

    public function test_delete_endpoint_rejects_unowned_credential(): void
    {
        $user = $this->makeUser();
        $service = app(\App\Services\PasskeyService::class);
        $service->storeCredential($user, $this->fakeRecord('AAAA'));

        $token = $this->loginToken($user);

        $this->withToken($token)
            ->deleteJson('/api/passkey', ['credential_id' => 'ZZZZ'])
            ->assertStatus(404);

        $this->assertSame([$this->b64('AAAA')], $service->getCredentialIds($user->fresh()));
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function fakeRecord(string $credIdHex): \Webauthn\CredentialRecord
    {
        $publicKey = hash('sha256', $credIdHex);
        $publicKey = pack('H*', $publicKey);

        return \Webauthn\CredentialRecord::create(
            hex2bin('aabb' . $credIdHex), // public key credential id
            'public-key',
            [], // transports
            'none',
            new \Webauthn\TrustPath\EmptyTrustPath(),
            \Symfony\Component\Uid\Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            $publicKey, // credentialPublicKey
            '', // userHandle
            0 // counter
        );
    }

    private function loginToken(User $user): string
    {
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ]);

        return $response->json('token');
    }

    private function b64(string $hex): string
    {
        return base64_encode(hex2bin('aabb' . $hex));
    }
}
