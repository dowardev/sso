<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\PasskeyService;
use App\Webauthn\PackedAttestationStatementSupportWithEdDSA;
use Cose\Algorithm\Manager as CoseAlgorithmManager;
use Cose\Algorithm\Signature\EdDSA\EdDSA;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Symfony\Component\Uid\Uuid;
use Tests\TestCase;
use Webauthn\AttestationStatement\AttestationStatementSupport;
use Webauthn\AttestationStatement\PackedAttestationStatementSupport;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\TrustPath\EmptyTrustPath;

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
        $service = app(PasskeyService::class);

        $options = $service->generateRegistrationOptions($user);
        $data = $service->serializeOptions($options);

        // PRF must be enabled so the authenticator exposes the DEK-wrap secret
        $this->assertTrue($data['extensions']['prf']['enable'] ?? false);
        // Options must carry a signed challenge and the Ed25519/ES256/RS256 algorithms
        $this->assertNotEmpty($data['challenge'] ?? '');
        $this->assertSame('public-key', $data['pubKeyCredParams'][0]['type'] ?? null);
    }

    /**
     * The relying party must advertise Ed25519 (COSE alg -8) at registration so
     * authenticators that prefer EdDSA (YubiKey 5+, Windows Hello, Android
     * Passkeys) can register without falling back. ES256 (-7) and RS256 (-257)
     * must remain on the list for legacy fallback.
     */
    public function test_registration_options_advertise_ed25519_algorithm(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);

        $options = $service->generateRegistrationOptions($user);
        $data = $service->serializeOptions($options);

        $algs = array_column($data['pubKeyCredParams'], 'alg');

        $this->assertContains(-8, $algs, 'Ed25519 (-8) must be in pubKeyCredParams');
        // Legacy fallback algorithms stay available.
        $this->assertContains(-7, $algs, 'ES256 (-7) must remain as fallback');
        $this->assertContains(-257, $algs, 'RS256 (-257) must remain as fallback');
    }

    public function test_options_round_trip_preserves_challenge(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);

        $options = $service->generateRegistrationOptions($user);
        $serialized = $service->serializeOptions($options);

        $rehydrated = $service->deserializeOptions($serialized, PublicKeyCredentialCreationOptions::class);

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
        $service = app(PasskeyService::class);

        $service->storeCredential($user, $this->fakeRecord('AAAA'), ['device_name' => 'Phone']);
        $service->storeCredential($user, $this->fakeRecord('BBBB'), ['device_name' => 'Key']);

        $this->assertSame([$this->b64('AAAA'), $this->b64('BBBB')], $service->getCredentialIds($user));
        $this->assertCount(2, $service->getUserCredentialRecords($user));
        $this->assertSame(2, PasskeyCredential::where('user_id', $user->id)->count());
    }

    public function test_service_removes_only_the_requested_credential(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);

        $service->storeCredential($user, $this->fakeRecord('AAAA'));
        $service->storeCredential($user, $this->fakeRecord('BBBB'));

        $service->removeCredential($user, $this->b64('AAAA'));

        $this->assertSame([$this->b64('BBBB')], $service->getCredentialIds($user));
        $this->assertSame(1, PasskeyCredential::where('user_id', $user->id)->count());
    }

    public function test_delete_endpoint_removes_a_single_credential_by_id(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);
        $service->storeCredential($user, $this->fakeRecord('AAAA'));
        $service->storeCredential($user, $this->fakeRecord('BBBB'));

        $token = $this->loginToken($user);

        $this->withToken($token)
            ->deleteJson('/api/passkey', ['credential_id' => $this->b64('AAAA'), 'password' => 'Password123'])
            ->assertOk();

        $this->assertSame([$this->b64('BBBB')], $service->getCredentialIds($user->fresh()));
    }

    public function test_delete_endpoint_rejects_unowned_credential(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);
        $service->storeCredential($user, $this->fakeRecord('AAAA'));

        $token = $this->loginToken($user);

        $this->withToken($token)
            ->deleteJson('/api/passkey', ['credential_id' => 'ZZZZ', 'password' => 'Password123'])
            ->assertStatus(404);

        $this->assertSame([$this->b64('AAAA')], $service->getCredentialIds($user->fresh()));
    }

    public function test_authenticate_start_resolves_basic_user_by_email(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);
        $service->storeCredential($user, $this->fakeRecord('AAAA'));

        $response = $this->postJson('/api/passkey/authenticate/start', [
            'username' => $user->email,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['challenge', 'allowCredentials']);
    }

    public function test_authenticate_start_returns_404_for_unknown_username(): void
    {
        $this->makeUser();

        $response = $this->postJson('/api/passkey/authenticate/start', [
            'username' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(404);
    }

    public function test_authenticate_start_discoverable_without_identity(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);
        $service->storeCredential($user, $this->fakeRecord('AAAA'));

        // No user_id / login_id / username -> resident-key (discoverable) ceremony.
        $response = $this->postJson('/api/passkey/authenticate/start', []);

        $response->assertOk();
        $response->assertJsonStructure(['challenge']);
        // Empty allowCredentials -> the browser shows the user's resident keys.
        $this->assertSame([], $response->json('allowCredentials'));
    }

    public function test_discoverable_finish_resolves_user_by_user_handle(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/passkey/authenticate/start', []);

        // userHandle = base64url of the user entity id ((string) $user->id).
        $userHandle = strtr(base64_encode((string) $user->id), '+/', '-_');

        $response = $this->postJson('/api/passkey/authenticate/finish', [
            'id' => 'unused',
            'response' => ['userHandle' => $userHandle],
        ]);

        // The user resolves from the userHandle; without credential records the
        // ceremony must reject with 400 "no tiene passkeys" (not 404/expired).
        $response->assertStatus(400);
        $response->assertJson(['message' => 'Usuario no tiene passkeys registrados']);
    }

    public function test_discoverable_finish_rejects_unknown_user_handle(): void
    {
        $this->makeUser();

        $this->postJson('/api/passkey/authenticate/start', []);

        $userHandle = strtr(base64_encode('99999'), '+/', '-_');

        $response = $this->postJson('/api/passkey/authenticate/finish', [
            'id' => 'unused',
            'response' => ['userHandle' => $userHandle],
        ]);

        $response->assertStatus(404);
    }

    public function test_register_start_supports_advanced_user_with_null_identity(): void
    {
        // Advanced (ZKE) users have null name/user/email — the user entity for
        // registration must still be built or the ceremony crashes at create().
        $user = User::factory()->create([
            'name' => null,
            'user' => null,
            'email' => null,
            'encryption_level' => 'advanced',
            'login_id' => 'adv-login-id==',
            'email_verified_at' => now(),
        ]);

        $service = app(PasskeyService::class);
        $options = $service->generateRegistrationOptions($user);
        $data = $service->serializeOptions($options);

        // The entity id is the raw user id (the WebAuthn userHandle the finish
        // step uses to resolve the user in the discoverable ceremony), and the
        // name/displayName fall back to neutral values (no PII leak).
        $this->assertSame(rtrim(strtr(base64_encode((string) $user->id), '+/', '-_'), '='), $data['user']['id']);
        $this->assertSame('user-'.$user->id, $data['user']['name']);
        $this->assertSame('Usuario '.$user->id, $data['user']['displayName']);
    }

    /**
     * The discoverable finish resolves the user from the userHandle. This test
     * locks the contract: the exact userHandle surface is the user id.
     */
    public function test_registered_entity_id_matches_user_id_for_user_handle(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);

        $options = $service->generateRegistrationOptions($user);
        $data = $service->serializeOptions($options);

        $this->assertSame(rtrim(strtr(base64_encode((string) $user->id), '+/', '-_'), '='), $data['user']['id']);
    }

    /**
     * The ceremony factory's algorithm manager must register EdDSA so the
     * CheckSignature step accepts Ed25519 (alg -8) signatures at both
     * attestation (registration) and assertion (login) time. The default
     * factory manager only ships ES256/RS256 — without explicit registration
     * every Ed25519 credential throws "Unsupported algorithm".
     */
    public function test_ceremony_factory_algorithm_manager_includes_eddsa(): void
    {
        $service = app(PasskeyService::class);

        $factory = (new ReflectionClass($service))->getProperty('factory')->getValue($service);
        $algorithmManager = (new ReflectionClass($factory))->getProperty('algorithmManager')->getValue($factory);

        $this->assertInstanceOf(CoseAlgorithmManager::class, $algorithmManager);
        $this->assertTrue($algorithmManager->has(-8), 'EdDSA (COSE alg -8) must be registered');
        $this->assertInstanceOf(EdDSA::class, $algorithmManager->get(-8));
        // Legacy algs stay available.
        $this->assertTrue($algorithmManager->has(-7));
        $this->assertTrue($algorithmManager->has(-257));
    }

    /**
     * The ceremony factory's attestation manager must support the "packed"
     * format — the common YubiKey / Windows Hello / Android Passkey
     * attestation. Without it the serializer rejects the response before
     * signature verification even runs.
     *
     * LBDS-286: the upstream `PackedAttestationStatementSupport` is wrapped in
     * `PackedAttestationStatementSupportWithEdDSA` so that the Ed25519 + x5c
     * case (which the upstream cannot verify because `openssl_verify` doesn't
     * handle EdDSA) is verified via libsodium. The wrapper registers itself
     * under `fmt="packed"` and forwards every non-EdDSA case to the wrapped
     * upstream instance, so the contract from this consumer's perspective is
     * unchanged: `has('packed')` is true and the handler is an
     * `AttestationStatementSupport`.
     */
    public function test_ceremony_factory_attestation_manager_supports_packed_format(): void
    {
        $service = app(PasskeyService::class);

        $factory = (new ReflectionClass($service))->getProperty('factory')->getValue($service);
        $attestationManager = (new ReflectionClass($factory))->getProperty('attestationStatementSupportManager')->getValue($factory);

        $this->assertTrue($attestationManager->has('packed'), 'fmt="packed" must be supported');
        $packedHandler = $attestationManager->get('packed');
        $this->assertInstanceOf(AttestationStatementSupport::class, $packedHandler);
        $this->assertSame('packed', $packedHandler->name());
        $this->assertInstanceOf(PackedAttestationStatementSupportWithEdDSA::class, $packedHandler);
        // NoneAttestationStatementSupport is added automatically by the manager.
        $this->assertTrue($attestationManager->has('none'));
    }

    /**
     * A user with multiple registered credentials (potentially across different
     * COSE algs after LBDS-257 lands Ed25519) must be excluded by id at
     * registration regardless of the credential's underlying algorithm.
     */
    public function test_registration_options_excludes_multiple_credentials_regardless_of_alg(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);

        // Two existing credential ids (mix of ES256 and Ed25519 in real life;
        // the service should not need to know which is which).
        $excludeA = $this->b64('AAAA');
        $excludeB = $this->b64('BBBB');

        $options = $service->generateRegistrationOptions($user, [$excludeA, $excludeB]);

        $this->assertCount(2, $options->excludeCredentials);
        $this->assertSame(base64_decode($excludeA), $options->excludeCredentials[0]->id);
        $this->assertSame(base64_decode($excludeB), $options->excludeCredentials[1]->id);
    }

    /**
     * Authentication must be able to enumerate multiple allowed credentials
     * (e.g. one ES256 + one Ed25519) without choking on the mix.
     */
    public function test_authentication_options_accepts_multiple_allowed_credentials(): void
    {
        $service = app(PasskeyService::class);

        $allowA = $this->b64('AAAA');
        $allowB = $this->b64('BBBB');

        $options = $service->generateAuthenticationOptions([$allowA, $allowB]);

        $this->assertCount(2, $options->allowCredentials);
        $this->assertSame(base64_decode($allowA), $options->allowCredentials[0]->id);
        $this->assertSame(base64_decode($allowB), $options->allowCredentials[1]->id);
    }

    // ─── LBDS-225: cross-device hybrid transport (QR) ──────────────────────────────────────────

    /**
     * When a credential is registered as `hybrid` (modern phones), the
     * authentication ceremony must forward that transport in
     * `allowCredentials[].transports`. Otherwise the browser silently
     * filters the credential out of its authenticator picker and the
     * cross-device QR flow is unreachable from a desktop without a local
     * passkey.
     */
    public function test_authentication_descriptor_forwards_stored_hybrid_transport(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);

        $service->storeCredential(
            $user,
            $this->fakeRecord('AAAA'),
            ['transports' => ['hybrid']]
        );

        $descriptors = $service->getAuthenticationDescriptors($user->fresh());
        $this->assertCount(1, $descriptors);
        $this->assertSame($service->getCredentialIds($user->fresh())[0], $descriptors[0]['credential_id']);
        $this->assertSame(['hybrid'], $descriptors[0]['transports']);

        $options = $service->generateAuthenticationOptions($descriptors);
        $serialized = $service->serializeOptions($options);

        $this->assertCount(1, $serialized['allowCredentials']);
        $this->assertSame(['hybrid'], $serialized['allowCredentials'][0]['transports']);
    }

    /**
     * Legacy credentials persisted before transports were tracked have an
     * empty stored transports array. The descriptor must omit the `transports`
     * field entirely so the browser is allowed to surface every available
     * authenticator — incl. the hybrid QR option when a phone-issued
     * credential happens to be in the allow list.
     */
    public function test_authentication_descriptor_omits_transports_when_empty(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);

        $service->storeCredential($user, $this->fakeRecord('AAAA'));

        $options = $service->generateAuthenticationOptions(
            $service->getAuthenticationDescriptors($user->fresh())
        );
        $serialized = $service->serializeOptions($options);

        $this->assertCount(1, $serialized['allowCredentials']);
        $this->assertArrayNotHasKey(
            'transports',
            $serialized['allowCredentials'][0],
            'Empty stored transports must NOT serialize as an empty array — omitting lets the browser offer every authenticator.'
        );
    }

    /**
     * Registration's `excludeCredentials` must be symmetric with login's
     * `allowCredentials` — same transport set. Otherwise a hybrid-registered
     * credential could be silently re-registered from a platform
     * authenticator on the same browser.
     */
    public function test_registration_exclude_credentials_forwards_stored_transports(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);

        $service->storeCredential(
            $user,
            $this->fakeRecord('AAAA'),
            ['transports' => ['hybrid', 'internal']]
        );

        $options = $service->generateRegistrationOptions(
            $user,
            $service->getRegistrationExcludeDescriptors($user->fresh())
        );
        $serialized = $service->serializeOptions($options);

        $this->assertCount(1, $serialized['excludeCredentials']);
        $transports = $serialized['excludeCredentials'][0]['transports'] ?? [];
        $this->assertContains('hybrid', $transports);
        $this->assertContains('internal', $transports);
    }

    /**
     * Garbage in stored transports (malformed DB write, manual override,
     * future spec drift) must be filtered before being forwarded to the
     * browser. Otherwise the descriptor could carry values the WebAuthn
     * spec doesn't define and the whole allow list could be silently
     * ignored.
     */
    public function test_descriptor_filters_unknown_transport_values(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);

        $service->storeCredential(
            $user,
            $this->fakeRecord('AAAA'),
            ['transports' => ['hybrid', 'magic-beam', 42, 'internal']]
        );

        $options = $service->generateAuthenticationOptions(
            $service->getAuthenticationDescriptors($user->fresh())
        );
        $serialized = $service->serializeOptions($options);

        $transports = $serialized['allowCredentials'][0]['transports'] ?? [];
        $this->assertSame(['hybrid', 'internal'], array_values($transports));
    }

    /**
     * End-to-end check through the controller: a user with a hybrid-registered
     * credential receives that transport in the JSON `allowCredentials[]`
     * returned by `/api/passkey/authenticate/start`. This is the wire format
     * the browser's `navigator.credentials.get()` reads.
     */
    public function test_authenticate_start_endpoint_forwards_stored_hybrid_transport(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);

        $service->storeCredential(
            $user,
            $this->fakeRecord('AAAA'),
            ['transports' => ['hybrid']]
        );

        $response = $this->postJson('/api/passkey/authenticate/start', [
            'username' => $user->email,
        ]);

        $response->assertOk();
        $allowCredentials = $response->json('allowCredentials');
        $this->assertCount(1, $allowCredentials);
        $this->assertSame(['hybrid'], $allowCredentials[0]['transports']);
    }

    /**
     * Same end-to-end check through the registration endpoint:
     * `excludeCredentials[]` carries the stored transport, not the legacy
     * hardcoded fallback.
     */
    public function test_register_start_endpoint_forwards_stored_hybrid_in_exclude(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);

        $service->storeCredential(
            $user,
            $this->fakeRecord('AAAA'),
            ['transports' => ['hybrid']]
        );

        // Re-enrollment requires an authenticated session — use the JWT flow.
        $token = $this->loginToken($user);

        $response = $this->withToken($token)
            ->postJson('/api/passkey/register/start', [
                'user_id' => $user->id,
            ]);

        $response->assertOk();
        $excludeCredentials = $response->json('excludeCredentials');
        $this->assertCount(1, $excludeCredentials);
        $this->assertSame(['hybrid'], $excludeCredentials[0]['transports']);
    }

    /**
     * F2 — The legacy string path of `buildDescriptor` (called when the
     * caller passes a bare base64 credential id, not the new array shape)
     * must also produce a descriptor with no `transports` field in JSON. The
     * array path is exercised by every other test; without this one a
     * regression that only affects the legacy input would slip through.
     */
    public function test_legacy_string_input_omits_transports_field(): void
    {
        $service = app(PasskeyService::class);
        $allowId = $this->b64('AAAA');

        $options = $service->generateAuthenticationOptions([$allowId]);
        $serialized = $service->serializeOptions($options);

        $this->assertCount(1, $serialized['allowCredentials']);
        $this->assertArrayNotHasKey('transports', $serialized['allowCredentials'][0]);
    }

    /**
     * F3 — `cable` is the WebAuthn-library constant kept for backwards
     * compatibility with older Android authenticators that registered under
     * the deprecated alias. `normalizeTransports` allows it through, and
     * that contract is worth pinning down so a future refactor doesn't
     * silently drop legacy credentials registered as `cable`.
     */
    public function test_cable_transport_is_forwarded(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);

        $service->storeCredential(
            $user,
            $this->fakeRecord('AAAA'),
            ['transports' => ['cable']]
        );

        $options = $service->generateAuthenticationOptions(
            $service->getAuthenticationDescriptors($user->fresh())
        );
        $serialized = $service->serializeOptions($options);

        $this->assertSame(['cable'], $serialized['allowCredentials'][0]['transports']);
    }

    /**
     * F4 — `smart-card` is a valid WebAuthn transport (e.g. PIV/CAC cards)
     * that the allowlist passes through. Pin the contract so a smart-card
     * user doesn't get silently blocked out of the QR-less authenticator
     * picker if someone tightens the allowlist.
     */
    public function test_smart_card_transport_is_forwarded(): void
    {
        $user = $this->makeUser();
        $service = app(PasskeyService::class);

        $service->storeCredential(
            $user,
            $this->fakeRecord('AAAA'),
            ['transports' => ['smart-card']]
        );

        $options = $service->generateAuthenticationOptions(
            $service->getAuthenticationDescriptors($user->fresh())
        );
        $serialized = $service->serializeOptions($options);

        $this->assertSame(['smart-card'], $serialized['allowCredentials'][0]['transports']);
    }

    /**
     * F1 — A descriptor entry that lacks `credential_id` (or carries an
     * empty / non-string one) must throw instead of silently building a
     * PublicKeyCredentialDescriptor with id=''. Without this guard,
     * base64_decode(null) emits a deprecation notice and yields an empty
     * id, producing a WebAuthn options blob that fails the assertion
     * ceremony with a confusing downstream error. Each documented failure
     * mode (missing key, null, non-string, empty string) is exercised so a
     * future refactor cannot silently drop one branch.
     *
     * R2 — The exception message itself is part of the contract: it
     * pinpoints the malformed descriptor entry so the upstream caller can
     * log the bad input. A silent message change would be a regression.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidDescriptorEntries(): array
    {
        return [
            'missing credential_id key' => [['transports' => ['hybrid']]],
            'null credential_id' => [['credential_id' => null, 'transports' => ['hybrid']]],
            'integer credential_id' => [['credential_id' => 42, 'transports' => ['hybrid']]],
            'empty-string credential_id' => [['credential_id' => '', 'transports' => ['hybrid']]],
        ];
    }

    #[DataProvider('invalidDescriptorEntries')]
    public function test_build_descriptor_rejects_invalid_credential_id(array $badEntry): void
    {
        $service = app(PasskeyService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Passkey descriptor entry is missing a non-empty credential_id');

        $service->generateAuthenticationOptions([$badEntry]);
    }

    /**
     * R4 — `normalizeTransports` is a pure allowlist+dedupe function. Its
     * contract is exercised end-to-end through F3/F4's integration tests,
     * but those go through DB writes and serialization to reach it. A
     * direct unit test pins the function's behavior in isolation so a
     * future refactor that touches internal wiring doesn't silently break
     * the filter. Reflection is the established pattern in this file
     * (`test_ceremony_factory_algorithm_manager_includes_eddsa` uses the
     * same trick).
     */
    public function test_normalize_transports_filters_unknown_values_and_dedupes(): void
    {
        $service = app(PasskeyService::class);
        $method = (new \ReflectionClass($service))->getMethod('normalizeTransports');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke($service, []));
        $this->assertSame([], $method->invoke($service, null));
        $this->assertSame([], $method->invoke($service, ['magic-beam', 'non-existent']));
        $this->assertSame(
            ['hybrid', 'internal'],
            $method->invoke($service, ['hybrid', 'magic-beam', 42, 'internal', 'hybrid'])
        );
        // Deprecated alias still flows through (library contract; see F3).
        $this->assertSame(['cable'], $method->invoke($service, ['cable']));
        // Smart-card is a first-class WebAuthn transport (see F4).
        $this->assertSame(['smart-card'], $method->invoke($service, ['smart-card']));
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function fakeRecord(string $credIdHex): CredentialRecord
    {
        $publicKey = hash('sha256', $credIdHex);
        $publicKey = pack('H*', $publicKey);

        return CredentialRecord::create(
            hex2bin('aabb'.$credIdHex), // public key credential id
            'public-key',
            [], // transports
            'none',
            new EmptyTrustPath,
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
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
        return base64_encode(hex2bin('aabb'.$hex));
    }
}
