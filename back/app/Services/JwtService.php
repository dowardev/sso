<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Services;

use App\Models\User;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

class JwtService
{
    private Configuration $jwtConfig;

    private int $tokenExpiration;

    private string $issuer;

    public function __construct()
    {
        $this->jwtConfig = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::file(config('jwt.private_key_path')),
            InMemory::file(config('jwt.public_key_path'))
        );

        $this->tokenExpiration = config('jwt.token_expiration', 3600);
        $this->issuer = config('jwt.issuer', 'http://localhost:8001');
    }

    public function getJwtConfig(): Configuration
    {
        return $this->jwtConfig;
    }

    public function getTokenExpiration(): int
    {
        return $this->tokenExpiration;
    }

    public function getIssuer(): string
    {
        return $this->issuer;
    }

    /**
     * Generate JWT token for a user.
     *
     * For advanced encryption level users, omits name/email from user claim
     * and adds encryption_level claim.
     */
    public function generateToken(User $user, ?string $dpopPublicKey = null, bool $rememberMe = false, ?string $encryptionLevel = null): array
    {
        $now = time();
        $expiresAt = $now + $this->tokenExpiration;

        $isAdvanced = $encryptionLevel === 'advanced' || $user->encryption_level === 'advanced';

        $userClaim = [
            'id' => $user->id,
            'user' => $user->user,
        ];

        // Include name/email only for basic users
        if (! $isAdvanced) {
            $userClaim['name'] = $user->name;
            $userClaim['email'] = $user->email;
        }

        $builder = $this->jwtConfig->builder()
            ->issuedBy($this->issuer)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt(new \DateTimeImmutable('@'.$now))
            ->expiresAt(new \DateTimeImmutable('@'.$expiresAt))
            ->relatedTo((string) $user->id)
            ->withClaim('user', $userClaim);

        // Add encryption_level claim for advanced users
        if ($isAdvanced) {
            $builder->withClaim('encryption_level', 'advanced');
        }

        if ($rememberMe) {
            $builder->withClaim('remember_me', true);
        }

        if ($dpopPublicKey) {
            $publicKeyData = json_decode($dpopPublicKey, true);
            if ($publicKeyData) {
                if (! isset($publicKeyData['kty']) || ! isset($publicKeyData['n']) || ! isset($publicKeyData['e'])) {
                    throw new \InvalidArgumentException('JWK incompleto: se requieren los campos kty, n y e');
                }
                if (($publicKeyData['kty'] ?? '') !== 'RSA') {
                    throw new \InvalidArgumentException('Tipo de clave JWK no soportado: se requiere RSA');
                }
                $jkt = $this->generateJWKThumbprint($publicKeyData);
                $builder->withClaim('cnf', [
                    'jkt' => $jkt,
                ]);
            }
        }

        $token = $builder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

        return [
            'token' => $token->toString(),
            'token_type' => $dpopPublicKey ? 'DPoP' : 'Bearer',
            'expires_in' => $this->tokenExpiration,
        ];
    }

    public function generateClientToken(string $clientId, ?string $grantType = null): array
    {
        $now = time();
        $expiresAt = $now + $this->tokenExpiration;

        $builder = $this->jwtConfig->builder()
            ->issuedBy($this->issuer)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt(new \DateTimeImmutable('@'.$now))
            ->expiresAt(new \DateTimeImmutable('@'.$expiresAt))
            ->relatedTo($clientId)
            ->withClaim('grant_type', $grantType ?? 'client_credentials')
            ->withClaim('client_id', $clientId);

        $token = $builder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

        return [
            'token' => $token->toString(),
            'token_type' => 'Bearer',
            'expires_in' => $this->tokenExpiration,
        ];
    }

    public function generateJWKThumbprint(array $jwk): string
    {
        $jwkString = json_encode([
            'e' => $jwk['e'] ?? null,
            'kty' => $jwk['kty'] ?? 'RSA',
            'n' => $jwk['n'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return rtrim(strtr(base64_encode(hash('sha256', $jwkString, true)), '+/', '-_'), '=');
    }

    public function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function parseAndValidateToken(string $tokenString): ?Plain
    {
        try {
            $token = $this->jwtConfig->parser()->parse($tokenString);

            $constraints = [
                new SignedWith(
                    $this->jwtConfig->signer(),
                    $this->jwtConfig->verificationKey()
                ),
                new IssuedBy($this->issuer),
            ];

            if (! $this->jwtConfig->validator()->validate($token, ...$constraints)) {
                return null;
            }

            return $token;
        } catch (\Exception $e) {
            return null;
        }
    }
}
