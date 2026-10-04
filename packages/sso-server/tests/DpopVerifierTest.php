<?php

namespace Dowardev\SsoServer\Tests;

use Dowardev\SsoServer\Dpop\DpopVerifier;
use Dowardev\SsoServer\Dpop\ReplayProtection;
use Dowardev\SsoServer\Exceptions\DpopVerificationException;
use Dowardev\SsoServer\Jwt\JwksFetcher;
use Dowardev\SsoServer\Jwt\JwkToPemConverter;
use Dowardev\SsoServer\Jwt\JwtValidator;
use Dowardev\SsoServer\Types\SsoConfig;
use Illuminate\Support\Facades\Cache;

class DpopVerifierTest extends TestCase
{
    private DpopVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $config = SsoConfig::fromConfig();
        $converter = new JwkToPemConverter;
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $jwtValidator = new JwtValidator($config, $jwksFetcher);
        $replayProtection = new ReplayProtection($config);

        $this->verifier = new DpopVerifier(
            $config,
            $converter,
            $jwtValidator,
            $replayProtection
        );
    }

    public function test_verifies_rs256_proof(): void
    {
        // Legacy RS256 path: pre-migration clients must continue to verify
        // until their stored RSA key rotates out at next login.
        $builder = RsaDpopProofBuilder::generate();

        $this->verifier->verify(
            dpopProof: $builder->proof(),
            accessToken: 'access-token-123',
            expectedJkt: $builder->jkt(),
            method: 'POST',
            uri: 'http://localhost/api/oauth/token',
        );

        $this->expectNotToPerformAssertions();
    }

    public function test_verifies_es256_proof(): void
    {
        // Post-migration ES256 path: the new clients (and the existing
        // sso-client library after this ticket) sign with ECDSA P-256.
        $builder = EcDpopProofBuilder::generate();

        $this->verifier->verify(
            dpopProof: $builder->proof(),
            accessToken: 'access-token-123',
            expectedJkt: $builder->jkt(),
            method: 'POST',
            uri: 'http://localhost/api/oauth/token',
        );

        $this->expectNotToPerformAssertions();
    }

    public function test_rejects_proof_with_wrong_jkt(): void
    {
        $builder = RsaDpopProofBuilder::generate();
        $other = RsaDpopProofBuilder::generate();

        $this->expectException(DpopVerificationException::class);
        $this->verifier->verify(
            dpopProof: $builder->proof(),
            accessToken: 'access-token-123',
            expectedJkt: $other->jkt(),
            method: 'POST',
            uri: 'http://localhost/api/oauth/token',
        );
    }

    public function test_rejects_proof_with_unsupported_alg(): void
    {
        // Build a proof whose header lies about its alg: the verifier must
        // reject HS256 (symmetric, not allowed for DPoP) before reaching
        // the signature stage.
        $builder = RsaDpopProofBuilder::generate();
        $proof = $builder->proofWithAlg('HS256');

        $this->expectException(DpopVerificationException::class);
        $this->verifier->verify(
            dpopProof: $proof,
            accessToken: 'access-token-123',
            expectedJkt: $builder->jkt(),
            method: 'POST',
            uri: 'http://localhost/api/oauth/token',
        );
    }

    public function test_rejects_proof_signed_by_other_ec_key(): void
    {
        // The proof carries the bound EC public key in its header, but the
        // signature is computed with a different private key. The verifier
        // must catch this even though the JWK thumbprint matches.
        $bound = EcDpopProofBuilder::generate();
        $other = EcDpopProofBuilder::generate();

        $this->expectException(DpopVerificationException::class);
        $this->verifier->verify(
            dpopProof: $bound->proof(['signer' => $other]),
            accessToken: 'access-token-123',
            expectedJkt: $bound->jkt(),
            method: 'POST',
            uri: 'http://localhost/api/oauth/token',
        );
    }

    public function test_rejects_proof_with_mismatched_alg_and_kty(): void
    {
        // S-1 (LBDS-258 review): an attacker must not be able to send
        // alg: ES256 with an RSA JWK (or vice versa). The cross-check
        // rejects it before the signature stage with a clear error.
        $rsa = RsaDpopProofBuilder::generate();
        $proofWithEs256Header = $rsa->proof(['alg' => 'ES256']);

        $this->expectException(DpopVerificationException::class);
        $this->expectExceptionMessage('alg ES256 requires kty EC, got RSA');
        $this->verifier->verify(
            dpopProof: $proofWithEs256Header,
            accessToken: 'access-token-123',
            expectedJkt: $rsa->jkt(),
            method: 'POST',
            uri: 'http://localhost/api/oauth/token',
        );
    }

    public function test_rejects_proof_with_rs256_alg_and_ec_kty(): void
    {
        // S-1 sibling: the inverse mismatch must also be rejected. Take a
        // real ES256 proof and rewrite its header alg to RS256 — the
        // signature is valid ES256 but the header claims RS256, which the
        // cross-check rejects before the signature stage.
        $ec = EcDpopProofBuilder::generate();
        $proof = $ec->proof();
        [$encodedHeader, $encodedPayload, $encodedSignature] = explode('.', $proof);

        $tamperedHeader = json_decode(
            base64_decode(strtr($encodedHeader, '-_', '+/')),
            true
        );
        $tamperedHeader['alg'] = 'RS256';
        $tamperedEncodedHeader = rtrim(
            strtr(base64_encode(json_encode($tamperedHeader)), '+/', '-_'),
            '='
        );
        $tamperedProof = $tamperedEncodedHeader.'.'.$encodedPayload.'.'.$encodedSignature;

        $this->expectException(DpopVerificationException::class);
        $this->expectExceptionMessage('alg RS256 requires kty RSA, got EC');
        $this->verifier->verify(
            dpopProof: $tamperedProof,
            accessToken: 'access-token-123',
            expectedJkt: $ec->jkt(),
            method: 'POST',
            uri: 'http://localhost/api/oauth/token',
        );
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}

/**
 * Mirrors the back/tests/Support/DpopProofBuilder for RSA proofs, scoped
 * to the sso-server unit suite so this test does not depend on the Laravel
 * app code.
 */
class RsaDpopProofBuilder
{
    public const DEFAULT_HTU = 'http://localhost/api/oauth/token';

    private function __construct(
        private string $privateKeyPem,
        private string $n,
        private string $e,
    ) {}

    public static function generate(): self
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new \RuntimeException('Unable to generate RSA keypair for DPoP tests');
        }

        openssl_pkey_export($resource, $privateKeyPem);
        $details = openssl_pkey_get_details($resource);

        return new self(
            $privateKeyPem,
            self::base64UrlEncode($details['rsa']['n']),
            self::base64UrlEncode($details['rsa']['e']),
        );
    }

    public function jwkArray(): array
    {
        return [
            'kty' => 'RSA',
            'n' => $this->n,
            'e' => $this->e,
        ];
    }

    public function jkt(): string
    {
        $canonical = json_encode([
            'e' => $this->e,
            'kty' => 'RSA',
            'n' => $this->n,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::base64UrlEncode(hash('sha256', $canonical, true));
    }

    /**
     * @param  array{alg?: string, htm?: string, htu?: string, iat?: int, jti?: string, ath?: string, signer?: self}  $params
     */
    public function proof(array $params = []): string
    {
        return $this->build($params, $params['alg'] ?? 'RS256');
    }

    /**
     * Build a proof whose alg header is forced to a specific value (used to
     * exercise the "unsupported alg" rejection path).
     */
    public function proofWithAlg(string $alg): string
    {
        return $this->build([], $alg);
    }

    private function build(array $params, string $alg): string
    {
        $htm = $params['htm'] ?? 'POST';
        $htu = $params['htu'] ?? self::DEFAULT_HTU;
        $iat = $params['iat'] ?? time();
        $jti = $params['jti'] ?? bin2hex(random_bytes(16));
        $ath = $params['ath'] ?? self::base64UrlEncode(hash('sha256', 'access-token-123', true));
        $signer = $params['signer'] ?? $this;

        $header = [
            'typ' => 'dpop+jwt',
            'alg' => $alg,
            'jwk' => $signer->jwkArray(),
        ];

        $payload = [
            'htm' => $htm,
            'htu' => $htu,
            'iat' => $iat,
            'jti' => $jti,
            'ath' => $ath,
        ];

        $encodedHeader = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $encodedPayload = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signingInput = $encodedHeader.'.'.$encodedPayload;

        openssl_sign($signingInput, $signature, $signer->privateKeyPem, OPENSSL_ALGO_SHA256);

        return $signingInput.'.'.self::base64UrlEncode($signature);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

/**
 * Builds ES256 DPoP proofs for the sso-server unit suite. Mirrors the
 * RSA one above; both share the same `proof()` shape so the verifier tests
 * can use either.
 *
 * P-256-specific: the DER→JOSE conversion in derToJose() hardcodes the
 * 32-byte point length, since the suite only generates P-256 keys. If a
 * future test needs P-384 or P-521, the hardcode must be lifted to a
 * curve-keyed lookup (see the production-side EC_CURVE_OIDS in
 * JwkToPemConverter for the production pattern).
 */
class EcDpopProofBuilder
{
    public const DEFAULT_HTU = 'http://localhost/api/oauth/token';

    /**
     * P-256 coordinate length in bytes (RFC 7518 §3.4).
     */
    private const POINT_LENGTH = 32;

    private function __construct(
        private string $privateKeyPem,
        private string $x,
        private string $y,
    ) {}

    public static function generate(): self
    {
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'ec' => ['curve_name' => 'prime256v1'],
        ]);

        if ($resource === false) {
            throw new \RuntimeException('Unable to generate EC keypair for DPoP tests');
        }

        openssl_pkey_export($resource, $privateKeyPem);
        $details = openssl_pkey_get_details($resource);

        return new self(
            $privateKeyPem,
            self::base64UrlEncode($details['ec']['x']),
            self::base64UrlEncode($details['ec']['y']),
        );
    }

    public function jwkArray(): array
    {
        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => $this->x,
            'y' => $this->y,
        ];
    }

    public function jkt(): string
    {
        $canonical = json_encode([
            'crv' => 'P-256',
            'kty' => 'EC',
            'x' => $this->x,
            'y' => $this->y,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::base64UrlEncode(hash('sha256', $canonical, true));
    }

    /**
     * @param  array{alg?: string, htm?: string, htu?: string, iat?: int, jti?: string, ath?: string, signer?: self}  $params
     */
    public function proof(array $params = []): string
    {
        $htm = $params['htm'] ?? 'POST';
        $htu = $params['htu'] ?? self::DEFAULT_HTU;
        $iat = $params['iat'] ?? time();
        $jti = $params['jti'] ?? bin2hex(random_bytes(16));
        $signer = $params['signer'] ?? $this;

        $header = [
            'typ' => 'dpop+jwt',
            'alg' => 'ES256',
            'jwk' => $signer->jwkArray(),
        ];

        $payload = [
            'htm' => $htm,
            'htu' => $htu,
            'iat' => $iat,
            'jti' => $jti,
            'ath' => self::base64UrlEncode(hash('sha256', 'access-token-123', true)),
        ];

        $encodedHeader = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $encodedPayload = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signingInput = $encodedHeader.'.'.$encodedPayload;

        openssl_sign(
            $signingInput,
            $signature,
            $signer->privateKeyPem,
            OPENSSL_ALGO_SHA256
        );

        // OpenSSL returns ASN.1 DER for ECDSA; JWT/DPoP requires raw R||S.
        return $signingInput.'.'.self::base64UrlEncode(self::derToJose($signature));
    }

    /**
     * Convert the ASN.1 DER signature OpenSSL produces to the JOSE raw
     * R || S encoding that JWT/DPoP uses. P-256-only; see class docblock.
     */
    private static function derToJose(string $der): string
    {
        $bytes = $der;
        $offset = 2; // SEQUENCE tag + length
        if (ord($bytes[1]) & 0x80) {
            $offset += ord($bytes[1]) & 0x7F;
        }

        $offset++; // INTEGER tag for r
        $rLen = ord($bytes[$offset++]);
        if ($rLen & 0x80) {
            $numBytes = $rLen & 0x7F;
            $rLen = 0;
            for ($i = 0; $i < $numBytes; $i++) {
                $rLen = ($rLen << 8) | ord($bytes[$offset++]);
            }
        }
        $rStart = $offset;
        if (ord($bytes[$rStart]) === 0x00 && $rLen > 1) {
            $offset++;
            $rLen--;
        }
        $r = substr($bytes, $offset, $rLen);
        $offset += $rLen;

        $offset++; // INTEGER tag for s
        $sLen = ord($bytes[$offset++]);
        if ($sLen & 0x80) {
            $numBytes = $sLen & 0x7F;
            $sLen = 0;
            for ($i = 0; $i < $numBytes; $i++) {
                $sLen = ($sLen << 8) | ord($bytes[$offset++]);
            }
        }
        $sStart = $offset;
        if (ord($bytes[$sStart]) === 0x00 && $sLen > 1) {
            $offset++;
            $sLen--;
        }
        $s = substr($bytes, $offset, $sLen);

        // P-256: 32 bytes per coordinate, big-endian, left-padded.
        $rPadded = str_pad($r, self::POINT_LENGTH, "\0", STR_PAD_LEFT);
        $sPadded = str_pad($s, self::POINT_LENGTH, "\0", STR_PAD_LEFT);

        return $rPadded.$sPadded;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
