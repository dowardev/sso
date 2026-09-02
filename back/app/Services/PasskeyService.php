<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Services;

use App\Models\PasskeyCredential;
use App\Models\User;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticationExtensions\AuthenticationExtensions;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;
use Exception;

class PasskeyService
{
    private CeremonyStepManagerFactory $factory;
    private CeremonyStepManager $creationCeremony;
    private CeremonyStepManager $requestCeremony;
    private AuthenticatorAttestationResponseValidator $attestationValidator;
    private AuthenticatorAssertionResponseValidator $assertionValidator;
    private PublicKeyCredentialRpEntity $rpEntity;
    private SerializerInterface $serializer;

    public function __construct()
    {
        $this->factory = new CeremonyStepManagerFactory();

        // Configure allowed origins (the browser origins driving WebAuthn)
        $appUrl = config('app.url', 'http://localhost:8101');
        $allowedOrigins = config('passkey.allowed_origins', ['http://localhost:8101']);
        $this->factory->setAllowedOrigins($allowedOrigins, true);

        // Configure relying party ID
        $rpId = config('passkey.rp_id', parse_url($appUrl, PHP_URL_HOST) ?? 'localhost');
        $this->factory->setSecuredRelyingPartyId([$rpId]);

        // Create ceremony managers
        $this->creationCeremony = $this->factory->creationCeremony();
        $this->requestCeremony = $this->factory->requestCeremony();

        // Create validators
        $this->attestationValidator = AuthenticatorAttestationResponseValidator::create($this->creationCeremony);
        $this->assertionValidator = AuthenticatorAssertionResponseValidator::create($this->requestCeremony);

        // Serializer builds/reads web-auth DTOs (options, responses) from JSON
        $this->serializer = (new WebauthnSerializerFactory(
            AttestationStatementSupportManager::create()
        ))->create();

        // Relying Party Entity
        $this->rpEntity = PublicKeyCredentialRpEntity::create(
            config('app.name', 'SSO Server'),
            $rpId,
            null
        );
    }

    /**
     * Generate registration options for a new passkey.
     *
     * The `prf` extension is enabled at creation time so the authenticator
     * exposes a device-bound PRF secret. The SSO client then wraps the ZKE
     * DEK with a key derived from that secret (HKDF), enabling silent profile
     * refresh gated only by a biometric gesture — never a password.
     */
    public function generateRegistrationOptions(User $user, array $excludeCredentials = []): PublicKeyCredentialCreationOptions
    {
        // web-auth v5 create(string $name, string $id, string $displayName).
        // The entity ID is the WebAuthn userHandle; for ZKE users name/email are
        // null by design, so fall back to a neutral local-only label.
        $userEntity = PublicKeyCredentialUserEntity::create(
            $user->user ?? $user->email ?? ('user-' . $user->id),
            (string) $user->id,
            $user->name ?? $user->user ?? $user->email ?? ('Usuario ' . $user->id),
            null
        );

        $excludeDescriptors = [];
        foreach ($excludeCredentials as $credId) {
            $excludeDescriptors[] = PublicKeyCredentialDescriptor::create(
                'public-key',
                base64_decode($credId),
                ['usb', 'nfc', 'ble', 'internal']
            );
        }

        return PublicKeyCredentialCreationOptions::create(
            $this->rpEntity,
            $userEntity,
            $this->generateChallenge(),
            [
                PublicKeyCredentialParameters::create('public-key', -7), // ES256
                PublicKeyCredentialParameters::create('public-key', -257), // RS256
            ],
            AuthenticatorSelectionCriteria::create(
                AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED
            ),
            null, // attestation
            $excludeDescriptors,
            60000, // timeout 60s
            AuthenticationExtensions::create(['prf' => ['enable' => true]])
        );
    }

    /**
     * Generate authentication options for passkey login.
     */
    public function generateAuthenticationOptions(array $allowCredentials = []): PublicKeyCredentialRequestOptions
    {
        $allowDescriptors = [];
        foreach ($allowCredentials as $credId) {
            $allowDescriptors[] = PublicKeyCredentialDescriptor::create(
                'public-key',
                base64_decode($credId),
                ['usb', 'nfc', 'ble', 'internal']
            );
        }

        return PublicKeyCredentialRequestOptions::create(
            $this->generateChallenge(),
            $this->rpEntity->id, // rpId: must equal the rp id used at registration,
                                 // otherwise the assertion's rpIdHash check fails
                                 // (registration uses rp->id; the Host header would
                                 // differ by scheme/port).
            $allowDescriptors,
            AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            null, // timeout
            null // extensions
        );
    }

    /**
     * Serialize web-auth options DTO to the JSON array the client consumes.
     */
    public function serializeOptions(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): array
    {
        $json = $this->serializer->serialize($options, 'json');

        return json_decode($json, true);
    }

    /**
     * Rehydrate a stored (serialized) creation/request options DTO. The
     * challenge round-trips through serialization, so the exact challenge the
     * client signed is reconstructed.
     */
    public function deserializeOptions(array $data, string $class): PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions
    {
        return $this->serializer->deserialize(json_encode($data), $class, 'json');
    }

    /**
     * Deserialize the client's PublicKeyCredential submission.
     */
    public function deserializePublicKeyCredential(array $data): PublicKeyCredential
    {
        return $this->serializer->deserialize(json_encode($data), PublicKeyCredential::class, 'json');
    }

    /**
     * Verify registration response (attestation) and return CredentialRecord.
     */
    public function verifyRegistrationResponse(
        PublicKeyCredentialCreationOptions $options,
        array $responseData,
        string $host
    ): CredentialRecord {
        $publicKeyCredential = $this->deserializePublicKeyCredential($responseData);

        /** @var AuthenticatorAttestationResponse $response */
        $response = $publicKeyCredential->response;

        return $this->attestationValidator->check($response, $options, $host);
    }

    /**
     * Verify authentication response (assertion) and return the credential record.
     */
    public function verifyAuthenticationResponse(
        PublicKeyCredentialRequestOptions $options,
        array $responseData,
        CredentialRecord $credentialRecord,
        string $host,
        ?string $userHandle = null
    ): CredentialRecord {
        $publicKeyCredential = $this->deserializePublicKeyCredential($responseData);

        /** @var AuthenticatorAssertionResponse $response */
        $response = $publicKeyCredential->response;

        return $this->assertionValidator->check($credentialRecord, $response, $options, $host, $userHandle);
    }

    /**
     * Build a CredentialRecord from a stored PasskeyCredential row.
     */
    public function createCredentialRecord(PasskeyCredential $credential): ?CredentialRecord
    {
        try {
            return CredentialRecord::create(
                base64_decode($credential->credential_id),
                'public-key',
                $credential->transports ?? [],
                'none',
                new EmptyTrustPath(),
                Uuid::fromString('00000000-0000-0000-0000-000000000000'),
                base64_decode($credential->public_key),
                $credential->user_handle ?? '',
                0 // counter
            );
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get all stored credential records for a user (multi-passkey).
     */
    public function getUserCredentialRecords(User $user): array
    {
        $records = [];
        foreach ($user->passkeyCredentials as $credential) {
            $record = $this->createCredentialRecord($credential);
            if ($record) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Get all stored credential ids (base64) for a user.
     */
    public function getCredentialIds(User $user): array
    {
        return $user->passkeyCredentials()
            ->pluck('credential_id')
            ->all();
    }

    /**
     * Store a new passkey credential (inserts — never overwrites).
     */
    public function storeCredential(User $user, CredentialRecord $record, array $meta = []): PasskeyCredential
    {
        return PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode($record->publicKeyCredentialId),
            'public_key' => base64_encode($record->credentialPublicKey),
            // Normalize to the user entity id string (what finish expects); a
            // stray authenticator userHandle would otherwise be persisted and
            // fail the assertion check at login.
            'user_handle' => (string) $user->id,
            'transports' => $meta['transports'] ?? null,
            'device_name' => $meta['device_name'] ?? null,
        ]);
    }

    /**
     * Remove a single credential by its base64 id.
     */
    public function removeCredential(User $user, string $credentialId): void
    {
        PasskeyCredential::where('user_id', $user->id)
            ->where('credential_id', $credentialId)
            ->delete();
    }

    /**
     * Get the relying party entity.
     */
    public function getRelyingPartyEntity(): PublicKeyCredentialRpEntity
    {
        return $this->rpEntity;
    }

    /**
     * Get the creation ceremony manager.
     */
    public function getCreationCeremony(): CeremonyStepManager
    {
        return $this->creationCeremony;
    }

    /**
     * Get the request ceremony manager.
     */
    public function getRequestCeremony(): CeremonyStepManager
    {
        return $this->requestCeremony;
    }

    private function generateChallenge(): string
    {
        return base64_encode(random_bytes(32));
    }
}