<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Jwt;

use Dowardev\SsoServer\Exceptions\SsoAuthenticationException;
use Dowardev\SsoServer\Types\SsoConfig;
use InvalidArgumentException;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as EcdsaSha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
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
        // We parse first, then conditionally hit the JWKS endpoint. Parsing
        // happens before the JWKS fetch, so a malformed token fails fast
        // without an HTTP call. At large JWKS sizes an attacker could
        // measure the unknown-kid path's O(n) iteration as a timing oracle;
        // out-of-scope today, tracked as part of the LBDS-258 multi-key work.
        $parsedToken = $this->parse($token);

        $kid = $parsedToken->headers()->get('kid');

        if ($kid !== null && $kid !== '') {
            // Kid-aware path: resolve PEM by kid. Throws JwksFetchException
            // when the kid is not present in the JWKS; we let it propagate
            // to match the existing behaviour for JWKS fetch failures.
            $publicKeyPem = $this->jwksFetcher->getPublicKeyPemByKid($kid);
        } else {
            // Back-compat: tokens issued before kid headers still verify
            // against the primary key.
            $publicKeyPem = $this->jwksFetcher->getPublicKeyPem();
        }

        $jwtConfig = Configuration::forAsymmetricSigner(
            new RsaSha256,
            InMemory::plainText('unused-signing-key'),
            InMemory::plainText($publicKeyPem)
        );

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
            new RsaSha256,
            InMemory::plainText('unused'),
            InMemory::plainText('unused')
        );

        return $config->parser()->parse($jwt);
    }

    /**
     * Verify a DPoP proof's signature against the public PEM derived from
     * its own header JWK. The signer is selected from the `alg` header so
     * RS256 (legacy) and ES256 (ES256/P-256, post-migration) proofs both
     * validate during the gradual rollout.
     */
    public function validateDpopProofSignature(string $dpopProof, string $pem): Token
    {
        // Parse twice: once with an inert config to discover the alg, then
        // a second time bound to the right signer. Parsing is cheap and the
        // header `alg` is the only legitimate way to pick a verifier here.
        $parsedToken = $this->parse($dpopProof);
        $alg = (string) $parsedToken->headers()->get('alg');

        $signer = $this->signerForAlgorithm($alg);

        $dpopConfig = Configuration::forAsymmetricSigner(
            $signer,
            InMemory::plainText('unused'),
            InMemory::plainText($pem)
        );

        $signedWith = new SignedWith($signer, $dpopConfig->verificationKey());
        if (! $dpopConfig->validator()->validate($parsedToken, $signedWith)) {
            throw SsoAuthenticationException::tokenInvalid('Firma del DPoP proof inválida');
        }

        return $parsedToken;
    }

    private function signerForAlgorithm(string $alg): Signer
    {
        return match ($alg) {
            'ES256' => new EcdsaSha256,
            'RS256' => new RsaSha256,
            default => throw new InvalidArgumentException("Unsupported DPoP alg: {$alg}"),
        };
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
