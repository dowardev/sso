<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Services;

use App\Models\SsoClient;
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

    private int $idTokenExpiration;

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
        $this->idTokenExpiration = (int) config('jwt.id_token_expiration', 600);
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
    public function generateToken(User $user, ?string $dpopPublicKey = null, bool $rememberMe = false, ?string $encryptionLevel = null, ?string $scope = null, ?string $clientId = null): array
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

        return $this->buildToken($user, null, $rememberMe, $encryptionLevel, (bool) $dpopPublicKey, $scope, $clientId);
    }

    /**
     * Generate a JWT bound to an already-known DPoP JWK thumbprint.
     *
     * Used on refresh-token rotation, where the binding is inherited from the
     * refresh token instead of being recomputed from a JWK request field.
     */
    public function generateTokenForJkt(User $user, ?string $dpopJkt, bool $rememberMe = false, ?string $encryptionLevel = null, ?string $scope = null, ?string $clientId = null): array
    {
        return $this->buildToken($user, $dpopJkt, $rememberMe, $encryptionLevel, $dpopJkt !== null, $scope, $clientId);
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
    private function buildToken(User $user, ?string $dpopJkt, bool $rememberMe, ?string $encryptionLevel, bool $dpopRequested, ?string $scope = null, ?string $clientId = null): array
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

        // LBDS-303: emit the OAuth2 `scope` claim (space-separated, RFC 6749
        // §3.3) when the caller granted a non-empty scope. OIDC clients read
        // this to know which claim groups (profile / email / openid) apply
        // for the userinfo and id_token — a missing `scope` claim is a
        // signal that the IdP does not understand OAuth2 scope at all.
        if ($scope !== null && $scope !== '') {
            $builder = $builder->withClaim('scope', $scope);
        }

        // LBDS-303: the access token carries the client_id so the OIDC
        // userinfo endpoint can resolve which SsoClient made the request
        // and apply the per-client claim_overrides. Without this claim,
        // the userinfo handler can only read the scope and the user, not
        // the requesting client.
        if ($clientId !== null && $clientId !== '') {
            $builder = $builder->withClaim('client_id', $clientId);
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

        $response = [
            'token' => $token->toString(),
            'token_type' => $dpopRequested ? 'DPoP' : 'Bearer',
            'expires_in' => $this->tokenExpiration,
        ];

        if ($scope !== null && $scope !== '') {
            $response['scope'] = $scope;
        }

        return $response;
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
     * Issue an OIDC Core 1.0 §2 id_token for the given user / client pair.
     *
     * Distinct from the access token in three ways:
     *   1. The `aud` claim is the client_id of the RP, not the user id
     *      (per OIDC §2: the id_token identifies the audience that should
     *      consume it).
     *   2. It carries a shorter lifetime (default 10 minutes) since it is
     *      meant to be verified once at login, not reused on every API call.
     *   3. It is NOT DPoP-bound — DPoP is for the access token, where the
     *      sender proves possession of the key. The id_token is a passive
     *      attestation and must be verifiable by the RP without a DPoP
     *      dance on the SSO.
     *
     * `grantedScopes` drives which claim groups are inlined (profile,
     * email). RPs that want the canonical values still call /oauth/userinfo
     * — the inlined claims are an optimization, not a substitute.
     *
     * `authTime` is when the user authenticated, in unix seconds. Null
     * means "we don't track this precisely" and the claim is omitted; the
     * OIDC spec makes auth_time OPTIONAL unless `max_age` is requested,
     * which the SSO does not support.
     *
     * `amr` and `acr` are placeholders. The right value depends on the
     * exact authentication method the user used at login, which lives on
     * the user's session row — wiring that is a follow-up. The current
     * values ("pwd" / "0") are honest defaults: every SSO login is at
     * least a password holder, and no auth class is claimed.
     */
    public function generateIdToken(
        User $user,
        SsoClient $client,
        ?string $nonce = null,
        array $grantedScopes = [],
        ?int $authTime = null,
        array $extraClaims = [],
    ): string {
        $now = time();
        $expiresAt = $now + $this->idTokenExpiration;

        $isAdvanced = $user->encryption_level === 'advanced';

        $builder = $this->jwtConfig->builder()
            ->issuedBy($this->issuer)
            ->identifiedBy((string) Str::uuid())
            ->issuedAt(new \DateTimeImmutable('@'.$now))
            ->expiresAt(new \DateTimeImmutable('@'.$expiresAt))
            ->relatedTo((string) $user->id)
            ->permittedFor($client->client_id)
            ->withClaim('acr', '0')
            ->withClaim('amr', ['pwd']);

        if ($nonce !== null && $nonce !== '') {
            $builder = $builder->withClaim('nonce', $nonce);
        }

        if ($authTime !== null) {
            $builder = $builder->withClaim('auth_time', $authTime);
        }

        if (in_array('profile', $grantedScopes, true)) {
            $builder = $builder->withClaim('name', $user->name);
            $builder = $builder->withClaim('preferred_username', $user->user);
            $builder = $builder->withClaim('updated_at', $user->updated_at?->getTimestamp());
        }

        if (in_array('email', $grantedScopes, true) && ! $isAdvanced) {
            $builder = $builder->withClaim('email', $user->email);
            $builder = $builder->withClaim('email_verified', $user->email_verified_at !== null);
        }

        // LBDS-303: per-client claim overrides. Values starting with
        // `user.` are resolved from the User model so a client can
        // request a dynamic claim (e.g. `user.email`) without the SSO
        // hardcoding a particular attribute name. Anything else is
        // emitted as a literal string. The id_token stays compliant
        // with OIDC §2: a custom claim can never shadow one of the
        // five REQUIRED ones (iss/sub/aud/exp/iat), nor the scope-
        // derived ones (name/preferred_username/updated_at/email/
        // email_verified) which are written earlier in this method —
        // an override named `email` would otherwise silently change
        // what the RP sees in the id_token vs. userinfo, an
        // inconsistency the OidcController::userinfo path also guards
        // against via the same reserved list.
        $reservedClaims = self::reservedClaims();

        foreach ($extraClaims as $name => $value) {
            if (! is_string($name) || $name === '' || in_array($name, $reservedClaims, true)) {
                continue;
            }

            $resolved = $this->resolveClaimValue($value, $user);
            $builder = $builder->withClaim($name, $resolved);
        }

        $builder = $builder->withHeader('kid', $this->kid);

        return $builder->getToken($this->jwtConfig->signer(), $this->signingKey)->toString();
    }

    /**
     * Resolve a single claim override value. Values starting with `user.`
     * are looked up on the User model; everything else is returned
     * unchanged. Returns null for unknown `user.<x>` paths so the
     * builder skips the claim instead of emitting the raw string —
     * failing silently here would be worse than skipping, but a
     * misconfigured override should not poison the whole id_token.
     */
    private function resolveClaimValue(string $value, User $user): mixed
    {
        if (! str_starts_with($value, 'user.')) {
            return $value;
        }

        $attribute = substr($value, 5);

        if ($attribute === '' || ! array_key_exists($attribute, $user->getAttributes())) {
            return null;
        }

        $raw = $user->getAttribute($attribute);

        // DateTime cast: OIDC timestamps are unix seconds, not ISO strings.
        if ($raw instanceof \DateTimeInterface) {
            return $raw->getTimestamp();
        }

        // Boolean for email_verified and similar: the OIDC expectation
        // is a JSON bool, not 0/1.
        if (is_string($raw) && in_array($raw, ['0', '1'], true) && $attribute === 'email_verified_at') {
            return $raw === '1';
        }

        return $raw;
    }

    /**
     * Claim names that a per-client `claim_overrides` entry must NOT
     * shadow. The list covers three groups:
     *
     *   - OIDC §2 REQUIRED claims: iss, sub, aud, exp, iat.
     *   - Claims the SSO writes by name: nonce, auth_time, acr, amr,
     *     jti. These are OPTIONAL per the spec, but the SSO emits
     *     them with specific values; an override that shadows them
     *     would either be a spec break (the REQUIRED ones) or a
     *     behaviour change (the OPTIONAL-but-emitted ones).
     *   - Scope-derived claims: name, preferred_username, updated_at,
     *     email, email_verified. These are written by the scope-driven
     *     claim loop and must be shared between id_token and
     *     /oauth/userinfo, so a per-client override that names one of
     *     them would otherwise make the two surfaces disagree.
     */
    public static function reservedClaims(): array
    {
        return [
            'iss', 'sub', 'aud', 'exp', 'iat',
            'nonce', 'auth_time', 'acr', 'amr', 'jti',
            'name', 'preferred_username', 'updated_at', 'email', 'email_verified',
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
            // kid; per the D4 fallback decision from the LBDS-256 design
            // review we fall
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
