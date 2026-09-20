<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Support;

/**
 * Builds DPoP proofs for feature tests.
 *
 * Generates an RSA-2048 keypair with openssl, exposes it as the public JWK
 * JSON the API accepts as `dpop_public_key`, computes the RFC 7638 thumbprint
 * (jkt), and signs `dpop+jwt` proofs (RSASSA-PKCS1-v1_5 + SHA-256).
 */
class DpopProofBuilder
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

    /**
     * The public JWK as the JSON string accepted by `dpop_public_key`.
     */
    public function publicKey(): string
    {
        return json_encode($this->jwkArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
     * Produce a signed `dpop+jwt` proof.
     *
     * Overrides: htm, htu, iat, jti, ath, signer (to sign with a different key,
     * which makes the header JWK no longer match the binding) and tamperSignature
     * (to keep the correct header JWK/thumbprint but break the signature).
     *
     * @param  array{htm?: string, htu?: string, iat?: int, jti?: string, ath?: string, signer?: self, tamperSignature?: bool}  $params
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
            'alg' => 'RS256',
            'jwk' => $signer->jwkArray(),
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

        openssl_sign($signingInput, $signature, $signer->privateKeyPem, OPENSSL_ALGO_SHA256);

        if ($tamperSignature) {
            // Flip a bit so the header JWK/thumbprint stay correct while the
            // RS256 signature over the signing input no longer verifies.
            $signature[0] = chr(ord($signature[0]) ^ 0x01);
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

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
