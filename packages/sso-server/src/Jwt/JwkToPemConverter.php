<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Jwt;

use InvalidArgumentException;

class JwkToPemConverter
{
    /**
     * The OIDs in the algorithmIdentifier.parameters field of an EC
     * SubjectPublicKeyInfo identify the curve by name. Only the curve we
     * currently support (ES256) is listed — anything else is rejected before
     * it reaches the signer, so a hostile client cannot trick the verifier
     * into using an exotic curve. P-384/P-521 are intentionally excluded
     * until the verifier gains matching signer support.
     */
    private const EC_CURVE_OIDS = [
        'P-256' => '1.2.840.10045.3.1.7',
    ];

    /**
     * The coordinate byte length for each supported EC curve. The PEM
     * SubjectPublicKeyInfo encodes the uncompressed point as 0x04 || x || y,
     * so the input JWK's x/y must decode to exactly these lengths.
     */
    private const EC_COORDINATE_LENGTHS = [
        'P-256' => 32,
    ];

    public function convert(array $jwk): string
    {
        $kty = $jwk['kty'] ?? '';

        if ($kty === 'EC') {
            return $this->convertEc($jwk);
        }

        if ($kty === 'RSA') {
            return $this->convertRsa($jwk);
        }

        throw new InvalidArgumentException("Unsupported JWK kty: {$kty}");
    }

    private function convertRsa(array $jwk): string
    {
        $n = $this->base64UrlDecode($jwk['n']);
        $e = $this->base64UrlDecode($jwk['e']);

        $asn1 = $this->buildRsaPublicKeyASN1($n, $e);

        return "-----BEGIN PUBLIC KEY-----\n".
            wordwrap(base64_encode($asn1), 64, "\n", true).
            "\n-----END PUBLIC KEY-----";
    }

    private function buildRsaPublicKeyASN1(string $n, string $e): string
    {
        $oid = $this->encodeASN1OID('1.2.840.113549.1.1.1');
        $algorithmIdentifier = $this->encodeASN1Sequence($oid.$this->encodeASN1Null());
        $rsaPublicKey = $this->encodeASN1Sequence(
            $this->encodeASN1Integer($n).$this->encodeASN1Integer($e)
        );
        $bitString = $this->encodeASN1BitString($rsaPublicKey);

        return $this->encodeASN1Sequence($algorithmIdentifier.$bitString);
    }

    private function convertEc(array $jwk): string
    {
        if (! isset($jwk['crv'], $jwk['x'], $jwk['y'])) {
            throw new InvalidArgumentException('EC JWK missing required fields (crv, x, y)');
        }

        $crv = (string) $jwk['crv'];
        if (! isset(self::EC_CURVE_OIDS[$crv])) {
            throw new InvalidArgumentException("Unsupported EC curve: {$crv}");
        }

        $x = $this->base64UrlDecode($jwk['x']);
        $y = $this->base64UrlDecode($jwk['y']);

        // Validate the coordinate length up front. OpenSSL would reject a
        // wrong-length point at import time, but with a generic error; this
        // gives a clear message and fails fast.
        $expectedLength = self::EC_COORDINATE_LENGTHS[$crv];
        if (strlen($x) !== $expectedLength || strlen($y) !== $expectedLength) {
            throw new InvalidArgumentException(
                "EC coordinates for {$crv} must be exactly {$expectedLength} bytes; got x=".strlen($x).', y='.strlen($y)
            );
        }

        // The uncompressed point format: 0x04 || x || y. OpenSSL's EC
        // importer is happy with either form; we always emit uncompressed so
        // the byte length is deterministic (1 + 2 * coordinateSize) and
        // easier to assert against in tests.
        $point = "\x04".$x.$y;

        // RFC 5480 §2.1.1 — AlgorithmIdentifier for EC:
        //   SEQUENCE { OID id-ecPublicKey (1.2.840.10045.2.1),
        //              OID <namedCurveId> }
        // parameters is the named curve OID itself, NOT a SEQUENCE wrapper.
        $algorithmIdentifier = $this->encodeASN1Sequence(
            $this->encodeASN1OID('1.2.840.10045.2.1')
            .$this->encodeASN1OID(self::EC_CURVE_OIDS[$crv])
        );
        $bitString = $this->encodeASN1BitString($point);

        $asn1 = $this->encodeASN1Sequence($algorithmIdentifier.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".
            wordwrap(base64_encode($asn1), 64, "\n", true).
            "\n-----END PUBLIC KEY-----";
    }

    private function encodeASN1Integer(string $value): string
    {
        if (ord($value[0]) & 0x80) {
            $value = "\x00".$value;
        }

        return "\x02".$this->encodeASN1Length(strlen($value)).$value;
    }

    private function encodeASN1Sequence(string $value): string
    {
        return "\x30".$this->encodeASN1Length(strlen($value)).$value;
    }

    private function encodeASN1BitString(string $value): string
    {
        $value = "\x00".$value;

        return "\x03".$this->encodeASN1Length(strlen($value)).$value;
    }

    private function encodeASN1Null(): string
    {
        return "\x05\x00";
    }

    private function encodeASN1OID(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $encoded = chr(40 * $parts[0] + $parts[1]);
        for ($i = 2; $i < count($parts); $i++) {
            if ($parts[$i] < 128) {
                $encoded .= chr($parts[$i]);
            } else {
                $temp = '';
                $val = $parts[$i];
                $temp = chr($val % 128).$temp;
                $val = intdiv($val, 128);
                while ($val > 0) {
                    $temp = chr(($val % 128) | 0x80).$temp;
                    $val = intdiv($val, 128);
                }
                $encoded .= $temp;
            }
        }

        return "\x06".$this->encodeASN1Length(strlen($encoded)).$encoded;
    }

    private function encodeASN1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $lengthBytes = '';
        $temp = $length;
        while ($temp > 0) {
            $lengthBytes = chr($temp & 0xFF).$lengthBytes;
            $temp >>= 8;
        }

        return chr(0x80 | strlen($lengthBytes)).$lengthBytes;
    }

    public function base64UrlDecode(string $data): string
    {
        $padded = str_pad(
            strtr($data, '-_', '+/'),
            strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4),
            '=',
            STR_PAD_RIGHT
        );

        return base64_decode($padded);
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
