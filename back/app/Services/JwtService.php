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
        if ($dpopPublicKey) {
            $publicKeyData = json_decode($dpopPublicKey, true);
            if ($publicKeyData) {
                if (! isset($publicKeyData['kty']) || ! isset($publicKeyData['n']) || ! isset($publicKeyData['e'])) {
                    throw new \InvalidArgumentException('JWK incompleto: se requieren los campos kty, n y e');
                }
                if (($publicKeyData['kty'] ?? '') !== 'RSA') {
                    throw new \InvalidArgumentException('Tipo de clave JWK no soportado: se requiere RSA');
                }
            }
        }

        return $this->buildToken($user, null, $rememberMe, $encryptionLevel, (bool) $dpopPublicKey);
    }

    /**
     * Generate a JWT bound to an already-known DPoP JWK thumbprint.
     *
     * Used on refresh-token rotation, where the binding is inherited from the
     * refresh token instead of being recomputed from a JWK request field.
     */
    public function generateTokenForJkt(User $user, ?string $dpopJkt, bool $rememberMe = false, ?string $encryptionLevel = null): array
    {
        return $this->buildToken($user, $dpopJkt, $rememberMe, $encryptionLevel, $dpopJkt !== null);
    }

    // NOTE: generateToken()'s payload is deliberately byte-identical to before.
    // The builder is immutable, so the historical `$builder->withClaim(...)` calls
    // for encryption_level/remember_me remained no-ops and only `cnf` is gated
    // (emitted by generateTokenForJkt()). Emitting `cnf` for every DPoP-capable
    // caller is separate work.

    /**
     * Shared claim builder for generateToken/generateTokenForJkt.
     *
     * @param  bool  $dpopRequested  Whether the caller asked for a DPoP token;
     *                               this preserves the historical token_type
     *                               for a malformed JWK (DPoP requested, no cnf).
     */
    private function buildToken(User $user, ?string $dpopJkt, bool $rememberMe, ?string $encryptionLevel, bool $dpopRequested): array
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

        if ($dpopJkt !== null) {
            $builder = $builder->withClaim('cnf', [
                'jkt' => $dpopJkt,
            ]);
        }

        $token = $builder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

        return [
            'token' => $token->toString(),
            'token_type' => $dpopRequested ? 'DPoP' : 'Bearer',
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

    /**
     * Issue a short-lived RS256 JWS attesting the authenticated user's current
     * advanced profile state. The SSO frontend appends it (alongside the
     * decrypted profile) as a `#profile=` fragment so OAuth apps can verify the
     * profile really originated from SSO and was not forged. The backend never
     * sees the plaintext profile (ZKE), so this only attests (sub, profile_version).
     */
    public function issueProfileToken(User $user): array
    {
        $now = time();
        $expiresAt = $now + 300;

        $builder = $this->jwtConfig->builder()
            ->issuedBy($this->issuer)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt(new \DateTimeImmutable('@'.$now))
            ->expiresAt(new \DateTimeImmutable('@'.$expiresAt))
            ->relatedTo((string) $user->id)
            ->withClaim('profile_version', (int) $user->profile_version);

        $token = $builder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

        return [
            'token' => $token->toString(),
            'expires_in' => 300,
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
