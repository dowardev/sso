<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Support;

/**
 * Builds DPoP proofs for feature tests.
 *
 * Two key types are supported to mirror the gradual RSA-2048 → ECDSA P-256
 * migration tracked by LBDS-258:
 *
 * - `generate()`:    RSA-2048 + RS256 (legacy path; pre-migration clients)
 * - `generateEc()`:  ECDSA P-256 + ES256 (post-migration clients)
 *
 * The class itself is algorithm-agnostic; the static factories just pick
 * the keypair, signer and header shape. `proof()`, `publicKey()`, `jkt()`
 * and the `ath()` helper all derive from whatever the builder was created
 * with, so feature tests can pin either end of the migration window.
 */
class DpopProofBuilder
{
    public const DEFAULT_HTU = 'http://localhost/api/oauth/token';

    /**
     * P-256 coordinate length in bytes (RFC 7518 §3.4). The DER→JOSE
     * conversion in derToJose() and the test fixtures hardcode this because
     * the suite only generates P-256 keys. The production middleware restricts
     * ES256 to P-256, so this matches what the verifier accepts.
     */
    private const P256_POINT_LENGTH = 32;

    private function __construct(
        private string $privateKeyPem,
        private string $alg,
        private string $kty,
        private array $publicJwkFields,
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
            'RS256',
            'RSA',
            [
                'kty' => 'RSA',
                'n' => self::base64UrlEncode($details['rsa']['n']),
                'e' => self::base64UrlEncode($details['rsa']['e']),
            ],
        );
    }

    public static function generateEc(): self
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
            'ES256',
            'EC',
            [
                'kty' => 'EC',
                'crv' => 'P-256',
                'x' => self::base64UrlEncode($details['ec']['x']),
                'y' => self::base64UrlEncode($details['ec']['y']),
            ],
        );
    }

    /**
     * The public JWK as the JSON string accepted by `dpop_public_key`.
     */
    public function publicKey(): string
    {
        return json_encode($this->publicJwkFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function jwkArray(): array
    {
        return $this->publicJwkFields;
    }

    /**
     * The JWK thumbprint the server will compute and bind to the access
     * token. The canonical serialization depends on the key type:
     *
     * - EC:  { crv, kty, x, y }   (RFC 7638 §3.2)
     * - RSA: { e, kty, n }        (RFC 7638 §3.3)
     */
    public function jkt(): string
    {
        if ($this->kty === 'EC') {
            $canonical = json_encode([
                'crv' => $this->publicJwkFields['crv'],
                'kty' => $this->publicJwkFields['kty'],
                'x' => $this->publicJwkFields['x'],
                'y' => $this->publicJwkFields['y'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $canonical = json_encode([
                'e' => $this->publicJwkFields['e'],
                'kty' => $this->publicJwkFields['kty'],
                'n' => $this->publicJwkFields['n'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return self::base64UrlEncode(hash('sha256', $canonical, true));
    }

    /**
     * Produce a signed `dpop+jwt` proof.
     *
     * Overrides: htm, htu, iat, jti, ath, signer (to sign with a different key,
     * which makes the header JWK no longer match the binding) and tamperSignature
     * (to keep the correct header JWK/thumbprint but break the signature).
     *
     * @param  array{alg?: string, htm?: string, htu?: string, iat?: int, jti?: string, ath?: string, signer?: self, tamperSignature?: bool}  $params
     */
    public function proof(array $params = []): string
    {
        $htm = $params['htm'] ?? 'POST';
        $htu = $params['htu'] ?? self::DEFAULT_HTU;
        $iat = $params['iat'] ?? time();
        $jti = $params['jti'] ?? bin2hex(random_bytes(16));
        $ath = $params['ath'] ?? null;
        $signer = $params['signer'] ?? $this;
        $tamperSignature = $params['tamperSignature'] ?? false;

        $header = [
            'typ' => 'dpop+jwt',
            'alg' => $signer->alg,
            'jwk' => $signer->publicJwkFields,
        ];

        $payload = [
            'htm' => $htm,
            'htu' => $htu,
            'iat' => $iat,
            'jti' => $jti,
        ];

        if ($ath !== null) {
            $payload['ath'] = $ath;
        }

        $encodedHeader = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $encodedPayload = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signingInput = $encodedHeader.'.'.$encodedPayload;

        openssl_sign(
            $signingInput,
            $signature,
            $signer->privateKeyPem,
            OPENSSL_ALGO_SHA256
        );

        if ($tamperSignature) {
            // Flip a bit so the header JWK/thumbprint stay correct while the
            // RS256 signature over the signing input no longer verifies.
            $signature[0] = chr(ord($signature[0]) ^ 0x01);
        }

        // OpenSSL emits ECDSA signatures as ASN.1 DER; JWT/DPoP requires raw
        // R || S (JOSE). RSA signatures are already raw bytes, so they pass
        // through unchanged.
        if ($signer->kty === 'EC') {
            $signature = self::derToJose($signature);
        }

        return $signingInput.'.'.self::base64UrlEncode($signature);
    }

    /**
     * RFC 7638 `ath` value for a given access token.
     */
    public static function ath(string $accessToken): string
    {
        return self::base64UrlEncode(hash('sha256', $accessToken, true));
    }

    /**
     * Convert the ASN.1 DER signature OpenSSL produces for ECDSA to the
     * JOSE raw R || S encoding that JWT/DPoP uses. The verifier mirrors
     * this conversion on the server side. P-256-only; see class docblock.
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
        $rPadded = str_pad($r, self::P256_POINT_LENGTH, "\0", STR_PAD_LEFT);
        $sPadded = str_pad($s, self::P256_POINT_LENGTH, "\0", STR_PAD_LEFT);

        return $rPadded.$sPadded;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
