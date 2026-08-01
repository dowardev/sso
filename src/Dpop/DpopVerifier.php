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

            $this->validateTypeAndAlgorithm($dpopToken);

            $jwkArray = $this->extractAndValidateJwk($dpopToken);

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

    private function validateTypeAndAlgorithm(Token $dpopToken): void
    {
        $typ = $this->jwtValidator->getHeader($dpopToken, 'typ');
        if ($typ !== 'dpop+jwt') {
            throw DpopVerificationException::invalidType();
        }

        $alg = $this->jwtValidator->getHeader($dpopToken, 'alg');
        if ($alg !== 'RS256') {
            throw DpopVerificationException::unsupportedAlgorithm((string) $alg);
        }
    }

    private function extractAndValidateJwk(Token $dpopToken): array
    {
        $jwkHeader = $this->jwtValidator->getHeader($dpopToken, 'jwk');
        if (! $jwkHeader) {
            throw DpopVerificationException::missingJwk();
        }

        $jwkArray = (array) $jwkHeader;
        if (! isset($jwkArray['kty']) || ! isset($jwkArray['n']) || ! isset($jwkArray['e'])) {
            throw DpopVerificationException::incompleteJwk();
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
