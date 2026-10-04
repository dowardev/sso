<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Dpop;

use Dowardev\SsoServer\Exceptions\DpopVerificationException;
use Dowardev\SsoServer\Jwt\JwkToPemConverter;
use Dowardev\SsoServer\Jwt\JwtValidator;
use Dowardev\SsoServer\Types\SsoConfig;
use Lcobucci\JWT\Token;

class DpopVerifier
{
    /**
     * Algorithms accepted in the DPoP proof header during the gradual
     * RSA-2048 → ECDSA P-256 migration. New clients must sign with ES256,
     * but RS256 remains valid until legacy keys rotate out at next login.
     */
    private const SUPPORTED_ALGS = ['RS256', 'ES256'];

    public function __construct(
        private readonly SsoConfig $config,
        private readonly JwkToPemConverter $converter,
        private readonly JwtValidator $jwtValidator,
        private readonly ReplayProtection $replayProtection,
    ) {}

    public function verify(
        string $dpopProof,
        string $accessToken,
        string $expectedJkt,
        string $method,
        string $uri,
    ): void {
        try {
            $dpopToken = $this->jwtValidator->parse($dpopProof);

            $alg = $this->validateTypeAndAlgorithm($dpopToken);

            $jwkArray = $this->extractAndValidateJwk($dpopToken, $alg);

            $this->validateThumbprint($jwkArray, $expectedJkt);

            $dpopPublicKeyPem = $this->converter->convert($jwkArray);
            $this->jwtValidator->validateDpopProofSignature($dpopProof, $dpopPublicKeyPem);

            $this->validateClaims($dpopToken, $accessToken, $method, $uri);

            $jti = $this->jwtValidator->getClaim($dpopToken, 'jti');
            $this->replayProtection->checkAndStore((string) $jti);
        } catch (DpopVerificationException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw DpopVerificationException::invalidProof($e->getMessage());
        }
    }

    private function validateTypeAndAlgorithm(Token $dpopToken): string
    {
        $typ = $this->jwtValidator->getHeader($dpopToken, 'typ');
        if ($typ !== 'dpop+jwt') {
            throw DpopVerificationException::invalidType();
        }

        $alg = (string) $this->jwtValidator->getHeader($dpopToken, 'alg');
        if (! in_array($alg, self::SUPPORTED_ALGS, true)) {
            throw DpopVerificationException::unsupportedAlgorithm($alg);
        }

        return $alg;
    }

    private function extractAndValidateJwk(Token $dpopToken, string $alg): array
    {
        $jwkHeader = $this->jwtValidator->getHeader($dpopToken, 'jwk');
        if (! $jwkHeader) {
            throw DpopVerificationException::missingJwk();
        }

        $jwkArray = (array) $jwkHeader;
        $kty = $jwkArray['kty'] ?? '';

        // alg/kty cross-check: an attacker must not be able to mix algorithms
        // and key types. The signer selection (ECDSA vs RSA) and the PEM
        // generation (EC vs RSA) both depend on these being consistent —
        // a mismatch is rejected with a clear error before the signature
        // verification step.
        if ($alg === 'ES256' && $kty !== 'EC') {
            throw DpopVerificationException::unsupportedAlgorithm("alg ES256 requires kty EC, got {$kty}");
        }
        if ($alg === 'RS256' && $kty !== 'RSA') {
            throw DpopVerificationException::unsupportedAlgorithm("alg RS256 requires kty RSA, got {$kty}");
        }

        if ($kty === 'EC') {
            if (! isset($jwkArray['crv'], $jwkArray['x'], $jwkArray['y'])) {
                throw DpopVerificationException::incompleteJwk();
            }
            if ($jwkArray['crv'] !== 'P-256') {
                throw DpopVerificationException::unsupportedAlgorithm('EC '.$jwkArray['crv']);
            }
        } elseif ($kty === 'RSA') {
            if (! isset($jwkArray['n'], $jwkArray['e'])) {
                throw DpopVerificationException::incompleteJwk();
            }
        } else {
            throw DpopVerificationException::unsupportedAlgorithm($kty ?: 'unknown');
        }

        return $jwkArray;
    }

    private function validateThumbprint(array $jwkArray, string $expectedJkt): void
    {
        $jktFromProof = JwkThumbprint::compute($jwkArray);
        if (! hash_equals($expectedJkt, $jktFromProof)) {
            throw DpopVerificationException::thumbprintMismatch();
        }
    }

    private function validateClaims(
        Token $dpopToken,
        string $accessToken,
        string $method,
        string $uri,
    ): void {
        $htm = $this->jwtValidator->getClaim($dpopToken, 'htm');
        $htu = $this->jwtValidator->getClaim($dpopToken, 'htu');
        $iat = $this->jwtValidator->getClaim($dpopToken, 'iat');
        $jti = $this->jwtValidator->getClaim($dpopToken, 'jti');
        $ath = $this->jwtValidator->getClaim($dpopToken, 'ath');

        if (! $htm || ! $htu || ! $iat || ! $jti || ! $ath) {
            throw DpopVerificationException::missingClaims();
        }

        if ($htm !== $method) {
            throw DpopVerificationException::methodMismatch();
        }

        if ($htu !== $uri) {
            throw DpopVerificationException::uriMismatch();
        }

        $now = time();
        $iatTimestamp = $iat instanceof \DateTimeInterface ? $iat->getTimestamp() : (int) $iat;
        if ($iatTimestamp > $now + $this->config->dpopIatLeeway || $iatTimestamp < $now - $this->config->dpopIatLeeway) {
            throw DpopVerificationException::timestampOutOfRange();
        }

        $tokenHash = JwkToPemConverter::base64UrlEncode(hash('sha256', $accessToken, true));
        if (! hash_equals($tokenHash, $ath)) {
            throw DpopVerificationException::accessTokenHashMismatch();
        }
    }
}
