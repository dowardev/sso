<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Webauthn;

use Cose\Algorithms;
use Psr\EventDispatcher\EventDispatcherInterface;
use SpomkyLabs\Pki\ASN1\Type\Primitive\OctetString;
use SpomkyLabs\Pki\ASN1\Type\UnspecifiedType;
use SpomkyLabs\Pki\CryptoEncoding\PEM;
use SpomkyLabs\Pki\CryptoTypes\AlgorithmIdentifier\Asymmetric\Ed25519AlgorithmIdentifier;
use SpomkyLabs\Pki\X501\ASN1\AttributeType;
use SpomkyLabs\Pki\X509\Certificate\Certificate;
use SpomkyLabs\Pki\X509\Certificate\TBSCertificate;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatement;
use Webauthn\AttestationStatement\AttestationStatementSupport;
use Webauthn\AttestationStatement\PackedAttestationStatementSupport;
use Webauthn\AuthenticatorData;
use Webauthn\Event\CanDispatchEvents;
use Webauthn\Event\NullEventDispatcher;
use Webauthn\Exception\AttestationStatementVerificationException;
use Webauthn\TrustPath\CertificateTrustPath;

/**
 * Packed attestation support that verifies Ed25519 (COSE alg x) signatures over
 * x5c certificate chains.
 *
 * Why this class exists
 * ---------------------
 * web-auth/webauthn-lib 5.x marks `PackedAttestationStatementSupport` as `final`
 * and keeps `processWithCertificate()` `private`. That method calls
 * `Cose\Algorithms::getOpensslAlgorithmFor($coseAlgorithmIdentifier)` to feed
 * `openssl_verify()`, which throws `InvalidArgumentException` for the EdDSA
 * algorithm identifier (x) because the cose-lib mapping has no OpenSSL entry
 * for it — and `openssl_verify` itself cannot handle Ed25519 at all.
 *
 * Consequence: any packed attestation that carries both an x5c chain AND the
 * EdDSA algorithm value (the common path for YubiKey 5 series, Windows Hello
 * TPM, and several Android devices) fails server-side at registration time.
 * Pure self-attestation EdDSA credentials succeed because that code path uses
 * the `AlgorithmManager`, where EdDSA IS registered (see `PasskeyService`).
 *
 * This wrapper fixes the x5c + EdDSA case by:
 *   1. Detecting `alg == EdDSA` AND `trustPath instanceof CertificateTrustPath`
 *      in `isValid()`.
 *   2. Re-running the FIDO format checks that the upstream
 *      `checkCertificate()` performs (Subject-O/OU/CN, non-CA, optional
 *      AAGUID extension) — re-implemented here because the upstream class is
 *      `final` and that method is `private`.
 *   3. Extracting the raw 32-byte Ed25519 public key from the leaf cert's
 *      SubjectPublicKeyInfo via the pki-framework classes.
 *   4. Verifying the signature with `sodium_crypto_sign_verify_detached` —
 *      the same primitive `Cose\Algorithm\Signature\EdDSA\EdDSA::verify()`
 *      uses for the self-attestation path.
 *
 * Every other case (non-EdDSA alg with x5c, all self-attestations, all
 * algorithm choices that the upstream already supports) is forwarded to the
 * wrapped upstream instance unchanged, so existing ES256 / RS256 flows keep
 * using the upstream's well-tested path.
 *
 * Upstream fix path: this class should become unnecessary once
 * web-auth/webauthn-lib supports Ed25519 in the x5c branch of packed
 * attestation (likely via `sodium_crypto_sign_verify_detached` or
 * `OpenSSLAsymmetricKey` ED25519 support). Track upstream PR/issue and
 * remove this wrapper when the lib release that contains the fix is pinned in
 * `composer.json`.
 *
 * @see https://gitlab.com/dowardev/sso/-/issues/LBDS-286
 */
final class PackedAttestationStatementSupportWithEdDSA implements AttestationStatementSupport, CanDispatchEvents
{
    private const FIDO_OID_AAGUID_EXTENSION = '1.3.6.1.4.1.45724.1.1.4';

    private const FIDO_ATTESTATION_OU = 'Authenticator Attestation';

    private EventDispatcherInterface $dispatcher;

    public function __construct(
        private readonly PackedAttestationStatementSupport $delegate
    ) {
        $this->dispatcher = new NullEventDispatcher;
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->dispatcher = $eventDispatcher;
        $this->delegate->setEventDispatcher($eventDispatcher);
    }

    public function name(): string
    {
        return 'packed';
    }

    /**
     * Loading is purely structural — the attestation CBOR map's shape doesn't
     * depend on the COSE algorithm value, so the upstream loader works for
     * EdDSA x5c attestations just as well as for ES256 / RS256 ones.
     *
     * @param  array<string, mixed>  $attestation
     */
    public function load(array $attestation): AttestationStatement
    {
        return $this->delegate->load($attestation);
    }

    public function isValid(
        string $clientDataJSONHash,
        AttestationStatement $attestationStatement,
        AuthenticatorData $authenticatorData
    ): bool {
        $trustPath = $attestationStatement->trustPath;
        $alg = (int) $attestationStatement->get('alg');

        // Only the EdDSA + x5c combination needs our workaround; every other
        // case flows through the upstream unchanged.
        if ($alg !== Algorithms::COSE_ALGORITHM_EDDSA || ! $trustPath instanceof CertificateTrustPath) {
            return $this->delegate->isValid($clientDataJSONHash, $attestationStatement, $authenticatorData);
        }

        return $this->verifyEdDSAWithCertificate(
            $clientDataJSONHash,
            $attestationStatement,
            $authenticatorData,
            $trustPath
        );
    }

    /**
     * Verify an Ed25519 signature over `authenticatorData || clientDataJSONHash`
     * using the public key from the leaf certificate of the attestation chain.
     */
    private function verifyEdDSAWithCertificate(
        string $clientDataJSONHash,
        AttestationStatement $attestationStatement,
        AuthenticatorData $authenticatorData,
        CertificateTrustPath $trustPath
    ): bool {
        $certificates = $trustPath->certificates;
        if ($certificates === [] || $certificates[0] === '') {
            throw AttestationStatementVerificationException::create(
                'Packed EdDSA attestation with x5c requires at least one certificate.'
            );
        }

        // Mirror the FIDO format checks the upstream `processWithCertificate`
        // does on every x5c chain. These are intentionally re-implemented
        // here because the upstream class is `final` and its
        // `checkCertificate()` is `private`. Keeping the policy check is a
        // defense-in-depth measure: it forces an attacker to also forge a cert
        // with `OU=Authenticator Attestation` and `CA=false`, not just any
        // Ed25519 keypair.
        $this->assertFidoAttestationCertificate($certificates[0], $authenticatorData);

        $publicKeyBytes = null;
        try {
            $certificate = Certificate::fromPEM(PEM::fromString($certificates[0]));
            $publicKeyInfo = $certificate->tbsCertificate()->subjectPublicKeyInfo();
            $algorithmIdentifier = $publicKeyInfo->algorithmIdentifier();
            // F2: only accept Ed25519 SPKIs. Defense-in-depth so a 32-byte
            // public key of a different algorithm can't pass the length
            // check below and reach `sodium_crypto_sign_verify_detached`.
            if ($algorithmIdentifier->oid() !== Ed25519AlgorithmIdentifier::OID_ED25519) {
                return false;
            }
            $publicKeyBytes = $publicKeyInfo->publicKey()->subjectPublicKey()->string();
        } catch (Throwable $e) {
            // F1: do not echo the underlying library's exception message into
            // our public error string — it can include ASN.1 internals, file
            // paths, and other details callers have no business seeing. The
            // upstream class also throws with static, redacted messages.
            throw AttestationStatementVerificationException::create(
                'Unable to extract Ed25519 public key from attestation certificate.'
            );
        }

        // F7: surface key-length mismatches as a verification failure (false)
        // rather than an exception. This matches the libsodium call below
        // and the upstream's `openssl_verify` failure path — both return
        // false instead of throwing. Only structural cert problems
        // (verified by `assertFidoAttestationCertificate` above) raise.
        if ($publicKeyBytes === null || strlen($publicKeyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        $signedData = $authenticatorData->authData.$clientDataJSONHash;
        /** @var string $signature */
        $signature = $attestationStatement->get('sig');

        try {
            return sodium_crypto_sign_verify_detached($signature, $signedData, $publicKeyBytes);
        } catch (Throwable $e) {
            // sodium_crypt on a malformed signature throws; surface that as
            // a verification failure rather than a 500.
            return false;
        }
    }

    /**
     * Re-implementation of the upstream `PackedAttestationStatementSupport::checkCertificate()`
     * private method — see class docblock for why this lives here. Throws
     * `AttestationStatementVerificationException` on any FIDO format mismatch;
     * returns void on success.
     */
    private function assertFidoAttestationCertificate(string $attestnCertPem, AuthenticatorData $authenticatorData): void
    {
        $certificate = Certificate::fromPEM(PEM::fromString($attestnCertPem));
        $tbsCertificate = $certificate->tbsCertificate();

        // X.509 version 3 is encoded as the integer 2 in the cert.
        $tbsCertificate->version() === TBSCertificate::VERSION_3 || throw AttestationStatementVerificationException::create(
            'Invalid certificate version'
        );

        $subject = $tbsCertificate->subject();
        $subject->countOfType(AttributeType::OID_COUNTRY_NAME) > 0
            || throw AttestationStatementVerificationException::create('Certificate Subject-C must be set');
        $subject->countOfType(AttributeType::OID_ORGANIZATION_NAME) > 0
            || throw AttestationStatementVerificationException::create('Certificate Subject-O must be set');
        $subject->countOfType(AttributeType::OID_ORGANIZATIONAL_UNIT_NAME) > 0
            || throw AttestationStatementVerificationException::create('Certificate Subject-OU must be set');
        $subject->countOfType(AttributeType::OID_COMMON_NAME) > 0
            || throw AttestationStatementVerificationException::create('Certificate Subject-CN must be set');

        $ouValue = $subject->firstValueOf('OU')->stringValue();
        $ouValue === self::FIDO_ATTESTATION_OU || throw AttestationStatementVerificationException::create(
            'Invalid certificate name. The Subject Organization Unit must be "Authenticator Attestation"'
        );

        $extensions = $tbsCertificate->extensions();
        $extensions->hasBasicConstraints()
            || throw AttestationStatementVerificationException::create(
                'The Basic Constraints extension must have the CA component set to false'
            );
        ! $extensions->basicConstraints()->isCA()
            || throw AttestationStatementVerificationException::create(
                'The Basic Constraints extension must have the CA component set to false'
            );

        $attestedCredentialData = $authenticatorData->attestedCredentialData;
        $attestedCredentialData !== null
            || throw AttestationStatementVerificationException::create('No attested credential available');

        if ($extensions->has(self::FIDO_OID_AAGUID_EXTENSION)) {
            $aaguidExtension = $extensions->get(self::FIDO_OID_AAGUID_EXTENSION);
            ! $aaguidExtension->isCritical()
                || throw AttestationStatementVerificationException::create(
                    'Extension '.self::FIDO_OID_AAGUID_EXTENSION.' must not be marked as critical'
                );

            $aaguidElement = UnspecifiedType::fromDER($aaguidExtension->extensionValue())->asElement();
            $aaguidElement instanceof OctetString
                || throw AttestationStatementVerificationException::create(
                    'Invalid '.self::FIDO_OID_AAGUID_EXTENSION.' extension format'
                );
            $aaguidValue = $aaguidElement->string();
            hash_equals($attestedCredentialData->aaguid->toBinary(), $aaguidValue)
                || throw AttestationStatementVerificationException::create(
                    'The value of the "aaguid" does not match with the certificate extension '.self::FIDO_OID_AAGUID_EXTENSION
                );
        }
    }
}
