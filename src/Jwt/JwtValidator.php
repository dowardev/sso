<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Jwt;

use Dowardev\SsoServer\Exceptions\SsoAuthenticationException;
use Dowardev\SsoServer\Types\SsoConfig;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

class JwtValidator
{
    public function __construct(
        private readonly SsoConfig $config,
        private readonly JwksFetcher $jwksFetcher,
    ) {}

    public function validate(string $token): Token
    {
        $publicKeyPem = $this->jwksFetcher->getPublicKeyPem();

        $jwtConfig = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText('unused-signing-key'),
            InMemory::plainText($publicKeyPem)
        );

        $parsedToken = $jwtConfig->parser()->parse($token);

        $constraints = [
            new SignedWith($jwtConfig->signer(), $jwtConfig->verificationKey()),
            new IssuedBy($this->config->jwtIssuer),
            new LooseValidAt(new SystemClock(new \DateTimeZone('UTC'))),
        ];

        if (! $jwtConfig->validator()->validate($parsedToken, ...$constraints)) {
            $this->jwksFetcher->clearCache();
            throw SsoAuthenticationException::tokenInvalid();
        }

        return $parsedToken;
    }

    public function parse(string $jwt): Token
    {
        $config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText('unused'),
            InMemory::plainText('unused')
        );

        return $config->parser()->parse($jwt);
    }

    public function validateDpopProofSignature(string $dpopProof, string $pem): Token
    {
        $verificationKey = InMemory::plainText($pem);
        $signer = new Sha256;

        $dpopConfig = Configuration::forAsymmetricSigner(
            $signer,
            InMemory::plainText('unused'),
            $verificationKey
        );

        $parsedToken = $dpopConfig->parser()->parse($dpopProof);

        $signedWith = new SignedWith($signer, $verificationKey);
        if (! $dpopConfig->validator()->validate($parsedToken, $signedWith)) {
            throw SsoAuthenticationException::tokenInvalid('Firma del DPoP proof inválida');
        }

        return $parsedToken;
    }

    public function getClaim(Token $token, string $claim): mixed
    {
        return $token->claims()->get($claim);
    }

    public function getHeader(Token $token, string $header): mixed
    {
        return $token->headers()->get($header);
    }
}
