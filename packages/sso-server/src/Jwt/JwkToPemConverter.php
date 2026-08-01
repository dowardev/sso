<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Jwt;

class JwkToPemConverter
{
    public function convert(array $jwk): string
    {
        $n = $this->base64UrlDecode($jwk['n']);
        $e = $this->base64UrlDecode($jwk['e']);

        $asn1 = $this->buildPublicKeyASN1($n, $e);

        return "-----BEGIN PUBLIC KEY-----\n".
            wordwrap(base64_encode($asn1), 64, "\n", true).
            "\n-----END PUBLIC KEY-----";
    }

    private function buildPublicKeyASN1(string $n, string $e): string
    {
        $oid = $this->encodeASN1OID('1.2.840.113549.1.1.1');
        $algorithmIdentifier = $this->encodeASN1Sequence($oid.$this->encodeASN1Null());
        $rsaPublicKey = $this->encodeASN1Sequence(
            $this->encodeASN1Integer($n).$this->encodeASN1Integer($e)
        );
        $bitString = $this->encodeASN1BitString($rsaPublicKey);

        return $this->encodeASN1Sequence($algorithmIdentifier.$bitString);
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
