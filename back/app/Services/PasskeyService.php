<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Services;

use App\Models\User;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialType;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\AuthenticatorTransport;
use Webauthn\AuthenticatorAttachment;
use Webauthn\AuthenticatorAuthenticatorAttachment;
use Webauthn\UserVerificationRequirement;
use Webauthn\CredentialRecord;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Exception;

class PasskeyService
{
    private CeremonyStepManagerFactory $factory;
    private CeremonyStepManager $creationCeremony;
    private CeremonyStepManager $requestCeremony;
    private AuthenticatorAttestationResponseValidator $attestationValidator;
    private AuthenticatorAssertionResponseValidator $assertionValidator;
    private PublicKeyCredentialRpEntity $rpEntity;

    public function __construct()
    {
        $this->factory = new CeremonyStepManagerFactory();
        
        // Configure allowed origins
        $appUrl = config('app.url', 'http://localhost:8101');
        $this->factory->setAllowedOrigins([$appUrl], true);
        
        // Configure relying party ID
        $rpId = config('passkey.rp_id', parse_url($appUrl, PHP_URL_HOST) ?? 'localhost');
        $this->factory->setSecuredRelyingPartyId([$rpId]);
        
        // Create ceremony managers
        $this->creationCeremony = $this->factory->creationCeremony();
        $this->requestCeremony = $this->factory->requestCeremony();
        
        // Create validators
        $this->attestationValidator = AuthenticatorAttestationResponseValidator::create($this->creationCeremony);
        $this->assertionValidator = AuthenticatorAssertionResponseValidator::create($this->requestCeremony);
        
        // Relying Party Entity
        $this->rpEntity = PublicKeyCredentialRpEntity::create(
            config('app.name', 'SSO Server'),
            $rpId,
            null
        );
    }

    /**
     * Generate registration options for a new passkey.
     */
    public function generateRegistrationOptions(User $user, array $excludeCredentials = []): PublicKeyCredentialCreationOptions
    {
        $userEntity = PublicKeyCredentialUserEntity::create(
            (string) $user->id,
            $user->user ?? $user->email,
            $user->name ?? $user->user ?? $user->email,
            null,
            null
        );

        $excludeDescriptors = [];
        foreach ($excludeCredentials as $credId) {
            $excludeDescriptors[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialType::PUBLIC_KEY,
                base64_decode($credId),
                [AuthenticatorTransport::USB, AuthenticatorTransport::NFC, AuthenticatorTransport::BLE, AuthenticatorTransport::INTERNAL]
            );
        }

        $options = PublicKeyCredentialCreationOptions::create(
            $this->rpEntity,
            $userEntity,
            PublicKeyCredentialParameters::create([
                PublicKeyCredentialParameters::create(PublicKeyCredentialType::PUBLIC_KEY, -7), // ES256
                PublicKeyCredentialParameters::create(PublicKeyCredentialType::PUBLIC_KEY, -257), // RS256
            ]),
            $excludeDescriptors,
            AuthenticatorSelectionCriteria::create(
                AuthenticatorAuthenticatorAttachment::PLATFORM, // prefer platform authenticators
                UserVerificationRequirement::REQUIRED,
                false // requireResidentKey
            ),
            null, // attestation
            null, // extensions
            60000 // timeout 60 seconds
        );

        return $options;
    }

    /**
     * Generate authentication options for passkey login.
     */
    public function generateAuthenticationOptions(array $allowCredentials = []): PublicKeyCredentialRequestOptions
    {
        $allowDescriptors = [];
        foreach ($allowCredentials as $credId) {
            $allowDescriptors[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialType::PUBLIC_KEY,
                base64_decode($credId),
                [AuthenticatorTransport::USB, AuthenticatorTransport::NFC, AuthenticatorTransport::BLE, AuthenticatorTransport::INTERNAL]
            );
        }

        $options = PublicKeyCredentialRequestOptions::create(
            $allowDescriptors,
            null, // timeout (use default)
            UserVerificationRequirement::PREFERRED,
            null, // extensions
            null // rpId (use default from relying party)
        );

        return $options;
    }

    /**
     * Verify registration response (attestation) and return CredentialRecord.
     */
    public function verifyRegistrationResponse(
        PublicKeyCredentialCreationOptions $options,
        array $responseData,
        string $host
    ): CredentialRecord {
        $response = AuthenticatorAttestationResponse::createFromArray($responseData);

        return $this->attestationValidator->check($response, $options, $host);
    }

    /**
     * Verify authentication response (assertion).
     */
    public function verifyAuthenticationResponse(
        PublicKeyCredentialRequestOptions $options,
        array $responseData,
        CredentialRecord $credentialRecord,
        string $host
    ): CredentialRecord {
        $response = AuthenticatorAssertionResponse::createFromArray($responseData);

        return $this->assertionValidator->check($response, $options, $credentialRecord, $host);
    }

    /**
     * Create a CredentialRecord from stored user data.
     */
    public function createCredentialRecord(User $user): ?CredentialRecord
    {
        if (! $user->passkey_public_key || ! $user->passkey_credential_id) {
            return null;
        }

        try {
            $credentialId = base64_decode($user->passkey_credential_id);
            $publicKey = base64_decode($user->passkey_public_key);

            return CredentialRecord::create(
                $credentialId,
                $publicKey,
                0, // counter
                [], // transports
                false, // backupEligibility
                false, // backupStatus
                [], // extensions
                '', // aaguid (not stored)
                null // userHandle
            );
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get stored credential sources for a user.
     */
    public function getUserCredentialRecords(User $user): array
    {
        $record = $this->createCredentialRecord($user);
        return $record ? [$record] : [];
    }

    /**
     * Store passkey credential for user.
     */
    public function storeCredential(User $user, CredentialRecord $credentialRecord): void
    {
        $user->passkey_credential_id = base64_encode($credentialRecord->credentialId);
        $user->passkey_public_key = base64_encode($credentialRecord->publicKey);
        $user->save();
    }

    /**
     * Remove passkey credential for user.
     */
    public function removeCredential(User $user): void
    {
        $user->passkey_credential_id = null;
        $user->passkey_public_key = null;
        $user->save();
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
}