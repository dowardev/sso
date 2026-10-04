<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key;
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

    /**
     * Single source of truth for the kid stamped into every JWT header.
     * Mirrors config('jwt.keys')[0]['kid']; verified at boot.
     */
    private string $kid;

    /**
     * Single source of truth for the signing key. Mirrors
     * config('jwt.keys')[0]['private_key_path']; loaded as an InMemory file
     * once and reused by every builder's getToken().
     */
    private Key $signingKey;

    /**
     * Single source of truth for the primary verification key. Mirrors
     * config('jwt.keys')[0]['public_key_path']; used as the back-compat
     * fallback when a token's `kid` header is absent.
     */
    private Key $verificationKey;

    /**
     * Active key set, keyed by kid. Built once per request from config('jwt.keys').
     * Forward-compatible: today a single entry, but the resolver already supports
     * multi-key rotation (LBDS-258). The first entry is treated as the primary.
     *
     * @var array<string, array{kid: string, public_key: Key, private_key: Key}>
     */
    private array $activeKeys;

    public function __construct()
    {
        $configured = config('jwt.keys', []);
        $primary = is_array($configured) ? ($configured[0] ?? null) : null;

        if (! is_array($primary)) {
            throw new \RuntimeException(
                'config/jwt.php `keys` must contain at least one entry; '
                .'without it the service cannot sign or verify JWTs.'
            );
        }

        $primaryKid = (string) ($primary['kid'] ?? '');
        $primaryPrivate = $primary['private_key_path'] ?? null;
        $primaryPublic = $primary['public_key_path'] ?? null;

        if ($primaryKid === '') {
            throw new \RuntimeException(
                'config/jwt.php `keys[0].kid` is required (got empty value).'
            );
        }

        if (! is_string($primaryPrivate) || $primaryPrivate === '') {
            throw new \RuntimeException(
                'config/jwt.php `keys[0].private_key_path` is required.'
            );
        }

        if (! is_string($primaryPublic) || $primaryPublic === '') {
            throw new \RuntimeException(
                'config/jwt.php `keys[0].public_key_path` is required.'
            );
        }

        // keys[0] is the canonical source of truth: signing, header kid, and
        // primary verification fallback all resolve from this entry. The top-
        // level `jwt.private_key_path` / `jwt.public_key_path` config values
        // are intentionally ignored here — see the comment in config/jwt.php.
        $this->kid = $primaryKid;
        $this->signingKey = InMemory::file($primaryPrivate);
        $this->verificationKey = InMemory::file($primaryPublic);

        // jwtConfig is kept only for parser() and signer() (the algorithm is
        // still RS256). We feed it the same keys[0] paths so it stays
        // internally consistent for any caller that uses builder()/signer().
        $this->jwtConfig = Configuration::forAsymmetricSigner(
            new Sha256,
            $this->signingKey,
            $this->verificationKey
        );

        $this->tokenExpiration = config('jwt.token_expiration', 3600);
        $this->issuer = config('jwt.issuer', 'http://localhost:8001');
        $this->activeKeys = $this->loadActiveKeys();
    }

    /**
     * Read the configured key set and load every public/private key into memory.
     * Single source of truth for both signing (token builders) and verification
     * (parseAndValidateToken). Adding a new key only requires a new entry in
     * config('jwt.keys') — no code change.
     *
     * @return array<string, array{kid: string, public_key: Key, private_key: Key}>
     */
    private function loadActiveKeys(): array
    {
        $configured = config('jwt.keys', []);
        $loaded = [];

        foreach ($configured as $entry) {
            $kid = (string) ($entry['kid'] ?? '');
            if ($kid === '') {
                continue;
            }

            $loaded[$kid] = [
                'kid' => $kid,
                'private_key' => InMemory::file($entry['private_key_path']),
                'public_key' => InMemory::file($entry['public_key_path']),
            ];
        }

        return $loaded;
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
                $kty = $publicKeyData['kty'] ?? null;
                if ($kty === 'RSA') {
                    if (! isset($publicKeyData['n'], $publicKeyData['e'])) {
                        throw new \InvalidArgumentException('JWK incompleto: se requieren los campos kty, n y e');
                    }
                } elseif ($kty === 'EC') {
                    // Allow EC P-256 only during the LBDS-258 migration
                    // window — an EC key with a different curve (or missing
                    // x/y) is rejected the same way the request validator
                    // rejects it.
                    if (! isset($publicKeyData['crv'], $publicKeyData['x'], $publicKeyData['y'])
                        || $publicKeyData['crv'] !== 'P-256') {
                        throw new \InvalidArgumentException('JWK EC P-256 incompleto: se requieren los campos kty, crv, x e y');
                    }
                } else {
                    throw new \InvalidArgumentException('Tipo de clave JWK no soportado: se requiere RSA o EC P-256');
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
            ->identifiedBy((string) Str::uuid())
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

        // LBDS-256: every issued JWT carries the configured kid in its
        // protected header so consumers can resolve the verification key by kid.
        $builder = $builder->withHeader('kid', $this->kid);

        $token = $builder->getToken($this->jwtConfig->signer(), $this->signingKey);

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
            ->identifiedBy((string) Str::uuid())
            ->issuedAt(new \DateTimeImmutable('@'.$now))
            ->expiresAt(new \DateTimeImmutable('@'.$expiresAt))
            ->relatedTo($clientId)
            ->withClaim('grant_type', $grantType ?? 'client_credentials')
            ->withClaim('client_id', $clientId);

        // LBDS-256: client-credentials tokens also carry the kid header so
        // resource servers can verify them by kid (same as user tokens).
        $builder = $builder->withHeader('kid', $this->kid);

        $token = $builder->getToken($this->jwtConfig->signer(), $this->signingKey);

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
            ->identifiedBy((string) Str::uuid())
            ->issuedAt(new \DateTimeImmutable('@'.$now))
            ->expiresAt(new \DateTimeImmutable('@'.$expiresAt))
            ->relatedTo((string) $user->id)
            ->withClaim('profile_version', (int) $user->profile_version);

        // LBDS-256: profile tokens (ZKE) carry the same kid header so OAuth apps
        // verifying them via /.well-known/jwks can match by kid.
        $builder = $builder->withHeader('kid', $this->kid);

        $token = $builder->getToken($this->jwtConfig->signer(), $this->signingKey);

        return [
            'token' => $token->toString(),
            'expires_in' => 300,
        ];
    }

    public function generateJWKThumbprint(array $jwk): string
    {
        // RFC 7638 §3 — fields MUST be in the order specified, no whitespace.
        // EC: { crv, kty, x, y }; RSA: { e, kty, n }. json_encode preserves
        // insertion order in PHP, so the array literal is the contract here.
        $kty = $jwk['kty'] ?? '';
        if ($kty === 'EC') {
            $jwkString = json_encode([
                'crv' => $jwk['crv'] ?? null,
                'kty' => $jwk['kty'] ?? 'EC',
                'x' => $jwk['x'] ?? null,
                'y' => $jwk['y'] ?? null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $jwkString = json_encode([
                'e' => $jwk['e'] ?? null,
                'kty' => $jwk['kty'] ?? 'RSA',
                'n' => $jwk['n'] ?? null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

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

            // LBDS-256: resolve the verification key by the kid claim in the
            // protected header. Tokens issued before this change don't carry a
            // kid; per D4 in odd/tasks/lbds-256-kid-header-jwts.md we fall
            // back to the primary key for back-compat. An unknown kid is a
            // hard error (matches the existing validation-failure contract:
            // returns null, no exception leaking).
            $tokenKid = $token->headers()->get('kid');
            $verificationKey = $this->resolveVerificationKey($tokenKid);

            if ($verificationKey === null) {
                return null;
            }

            $constraints = [
                new SignedWith(
                    $this->jwtConfig->signer(),
                    $verificationKey
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

    /**
     * Resolve the public key used to verify a token's signature by its kid
     * header. Null when the kid is present but unknown — callers (only
     * parseAndValidateToken today) treat that as a validation failure.
     *
     * Fallback rule (D4):
     *   - kid present & matches any configured key  → that key
     *   - kid absent                                 → primary key (compat)
     *   - kid present & unknown                      → null
     */
    private function resolveVerificationKey(mixed $tokenKid): ?Key
    {
        if ($tokenKid === null || $tokenKid === '' || ! is_string($tokenKid)) {
            // Absent / empty / non-string kid → primary key (back-compat with
            // tokens issued before this change). $this->verificationKey
            // mirrors config('jwt.keys')[0]['public_key_path'] and is the
            // canonical primary.
            return $this->verificationKey;
        }

        if (! isset($this->activeKeys[$tokenKid])) {
            return null;
        }

        return $this->activeKeys[$tokenKid]['public_key'];
    }

    /**
     * Get the kid value used by every JWT this service issues.
     */
    public function getKid(): string
    {
        return $this->kid;
    }
}
