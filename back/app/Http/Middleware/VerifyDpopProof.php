<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as EcdsaSha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Symfony\Component\HttpFoundation\Response;

class VerifyDpopProof
{
    /**
     * Algorithms accepted in the DPoP proof header during the gradual
     * RSA-2048 → ECDSA P-256 migration. The signer is chosen from this set
     * based on the `alg` header of each incoming proof.
     */
    private const SUPPORTED_ALGS = ['RS256', 'ES256'];

    /**
     * The OIDs in the algorithmIdentifier.parameters field of an EC
     * SubjectPublicKeyInfo identify the curve by name. Only the curve we
     * currently support (ES256) is listed — P-384/P-521 are intentionally
     * excluded until the verifier gains matching signer support, so a
     * proof with a non-P-256 curve fails fast instead of silently using
     * the wrong signer.
     */
    private const EC_CURVE_OIDS = [
        'P-256' => '1.2.840.10045.3.1.7',
    ];

    /**
     * Coordinate byte length for each supported EC curve. The PEM
     * SubjectPublicKeyInfo encodes the uncompressed point as 0x04 || x || y,
     * so the input JWK's x/y must decode to exactly these lengths.
     */
    private const EC_COORDINATE_LENGTHS = [
        'P-256' => 32,
    ];

    private Configuration $jwtConfig;

    public function __construct()
    {
        // Parse-only config: the parser does not need real RSA keys, just a
        // signer hint. Per-proof verification builds its own Configuration
        // bound to the right signer (RS256 or ES256) below.
        $this->jwtConfig = Configuration::forAsymmetricSigner(
            new RsaSha256,
            InMemory::file(base_path(env('JWT_PRIVATE_KEY_PATH', 'keys/private.pem'))),
            InMemory::file(base_path(env('JWT_PUBLIC_KEY_PATH', 'keys/public.pem')))
        );
    }

    public function handle(Request $request, Closure $next): Response
    {
        $accessToken = $request->bearerToken();
        $dpopHeader = $request->header('DPoP');

        $result = $this->verify($accessToken, $dpopHeader, $request);
        if ($result !== null) {
            return $result;
        }

        return $next($request);
    }

    public function verify(?string $accessToken, ?string $dpopHeader, Request $request): ?Response
    {
        if (! $accessToken) {
            return response()->json([
                'message' => 'Token de acceso requerido',
            ], 401);
        }

        if (! $dpopHeader) {
            return response()->json([
                'message' => 'DPoP proof requerido para tokens vinculados',
            ], 401);
        }

        try {
            $parsedAccessToken = $this->jwtConfig->parser()->parse($accessToken);
            $cnf = $parsedAccessToken->claims()->get('cnf');

            if (! $cnf || ! isset($cnf['jkt'])) {
                return response()->json([
                    'message' => 'El token no está vinculado a una clave DPoP',
                ], 401);
            }

            $expectedJkt = $cnf['jkt'];

            $dpopToken = $this->jwtConfig->parser()->parse($dpopHeader);

            $this->validateDpopProof($dpopToken, $request, $expectedJkt, $accessToken, true);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'DPoP proof inválido: '.$e->getMessage(),
            ], 401);
        }

        return null;
    }

    /**
     * Verify a DPoP proof presented at the OAuth token endpoint.
     *
     * Unlike verify(), there is no access token binding yet, so the expected
     * thumbprint is supplied by the caller (the refresh token's dpop_jkt) and
     * the ath claim is optional: it only has to match when the client sends one.
     */
    public function verifyTokenRequest(?string $dpopHeader, Request $request, string $expectedJkt, ?string $accessToken = null): ?Response
    {
        if (! $dpopHeader) {
            return response()->json([
                'message' => 'DPoP proof requerido para tokens vinculados',
            ], 401);
        }

        try {
            $dpopToken = $this->jwtConfig->parser()->parse($dpopHeader);

            $this->validateDpopProof($dpopToken, $request, $expectedJkt, $accessToken, false);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'DPoP proof inválido: '.$e->getMessage(),
            ], 401);
        }

        return null;
    }

    /**
     * Shared proof validation used by verify() and verifyTokenRequest().
     *
     * @param  bool  $requireAth  verify() requires ath (access-token binding);
     *                            the token endpoint accepts a proof without it.
     */
    private function validateDpopProof($dpopToken, Request $request, string $expectedJkt, ?string $accessToken, bool $requireAth): void
    {
        $header = $dpopToken->headers();

        if ($header->get('typ') !== 'dpop+jwt') {
            throw new \Exception('Tipo de token DPoP inválido');
        }

        $alg = (string) $header->get('alg');
        if (! in_array($alg, self::SUPPORTED_ALGS, true)) {
            throw new \Exception('Algoritmo DPoP no soportado: '.$alg);
        }

        $jwkHeader = $header->get('jwk');
        if (! $jwkHeader) {
            throw new \Exception('Falta la clave pública JWK en el header DPoP');
        }

        $jwkArray = (array) $jwkHeader;
        $kty = $jwkArray['kty'] ?? '';

        // alg/kty cross-check: an attacker must not be able to mix algorithms
        // and key types. The signer selection (ECDSA vs RSA) and the PEM
        // generation (EC vs RSA) both depend on these being consistent —
        // a mismatch is rejected with a clear error before the signature
        // verification step.
        if ($alg === 'ES256' && $kty !== 'EC') {
            throw new \Exception("alg ES256 requiere kty EC, se recibió {$kty}");
        }
        if ($alg === 'RS256' && $kty !== 'RSA') {
            throw new \Exception("alg RS256 requiere kty RSA, se recibió {$kty}");
        }

        if ($kty === 'EC') {
            if (! isset($jwkArray['crv'], $jwkArray['x'], $jwkArray['y'])) {
                throw new \Exception('JWK del DPoP proof incompleto: faltan campos requeridos (crv, x, y)');
            }
            if (! isset(self::EC_CURVE_OIDS[$jwkArray['crv']])) {
                throw new \Exception('Curva EC no soportada: '.$jwkArray['crv']);
            }
        } elseif ($kty === 'RSA') {
            if (! isset($jwkArray['n'], $jwkArray['e'])) {
                throw new \Exception('JWK del DPoP proof incompleto: faltan campos requeridos (n, e)');
            }
        } else {
            throw new \Exception('Tipo de clave JWK no soportado: '.($kty ?: 'desconocido'));
        }

        $jktFromProof = $this->generateJWKThumbprint($jwkArray);
        if (! hash_equals($expectedJkt, $jktFromProof)) {
            throw new \Exception('La huella digital JWK del DPoP proof no coincide con la vinculada al token');
        }

        $dpopPublicKey = $this->jwkToPem($jwkArray);

        $verificationKey = InMemory::plainText($dpopPublicKey);
        $signer = $this->signerForAlgorithm($alg);

        $proofConfig = Configuration::forAsymmetricSigner(
            $signer,
            InMemory::plainText('unused'),
            $verificationKey
        );

        $constraint = new SignedWith($signer, $verificationKey);

        if (! $proofConfig->validator()->validate($dpopToken, $constraint)) {
            throw new \Exception('Firma del DPoP proof inválida');
        }

        $payload = $dpopToken->claims();

        $htm = $payload->get('htm');
        $htu = $payload->get('htu');
        $iat = $payload->get('iat');
        $jti = $payload->get('jti');
        $ath = $payload->get('ath');

        if (! $htm || ! $htu || ! $iat || ! $jti || ($requireAth && ! $ath)) {
            if ($requireAth) {
                throw new \Exception('DPoP proof incompleto: faltan claims requeridos (htm, htu, iat, jti, ath)');
            }

            throw new \Exception('DPoP proof incompleto: faltan claims requeridos (htm, htu, iat, jti)');
        }

        if ($htm !== $request->method()) {
            throw new \Exception('Método HTTP no coincide en DPoP proof');
        }

        $baseUrl = $request->getSchemeAndHttpHost().$request->getBaseUrl();
        $expectedHtu = $baseUrl.$request->getPathInfo();

        if ($htu !== $expectedHtu && $htu !== $request->fullUrl()) {
            throw new \Exception('URI HTTP no coincide en DPoP proof');
        }

        $now = time();
        $iatTimestamp = $iat instanceof \DateTimeInterface ? $iat->getTimestamp() : (int) $iat;
        if ($iatTimestamp > $now + 60 || $iatTimestamp < $now - 60) {
            throw new \Exception('Timestamp del DPoP proof fuera de rango');
        }

        if ($ath !== null) {
            if ($accessToken === null) {
                throw new \Exception('ath presente en DPoP proof pero no hay token de acceso');
            }

            $tokenHash = rtrim(strtr(base64_encode(hash('sha256', $accessToken, true)), '+/', '-_'), '=');
            if (! hash_equals($tokenHash, $ath)) {
                throw new \Exception('Hash del token de acceso no coincide en DPoP proof');
            }
        }

        $inserted = DB::affectingStatement('INSERT IGNORE INTO dpop_nonces (jti, expires_at) VALUES (?, ?)', [
            $jti,
            now()->addMinutes(2),
        ]);

        if ($inserted === 0) {
            throw new \Exception('DPoP proof reutilizado (replay detectado)');
        }

        $this->lazyCleanup();
    }

    private function signerForAlgorithm(string $alg): Signer
    {
        return match ($alg) {
            'ES256' => new EcdsaSha256,
            'RS256' => new RsaSha256,
            default => throw new \Exception('Algoritmo DPoP no soportado: '.$alg),
        };
    }

    private function generateJWKThumbprint(array $jwk): string
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

    private function jwkToPem(array $jwk): string
    {
        $kty = $jwk['kty'] ?? '';

        if ($kty === 'EC') {
            return $this->ecJwkToPem($jwk);
        }

        return $this->rsaJwkToPem($jwk);
    }

    private function rsaJwkToPem(array $jwk): string
    {
        $nBin = $this->base64UrlDecode($jwk['n']);
        $eBin = $this->base64UrlDecode($jwk['e']);

        // SubjectPublicKeyInfo for an RSA key: SEQUENCE(AlgorithmIdentifier,
        // BIT STRING(SEQUENCE(n, e))).
        $asn1 = $this->encodeASN1Sequence(
            $this->encodeASN1Sequence(
                $this->encodeASN1OID('1.2.840.113549.1.1.1').
                $this->encodeASN1Null()
            ).
            $this->encodeASN1BitString(
                $this->encodeASN1Sequence(
                    $this->encodeASN1Integer($nBin).
                    $this->encodeASN1Integer($eBin)
                )
            )
        );

        return "-----BEGIN PUBLIC KEY-----\n".
            wordwrap(base64_encode($asn1), 64, "\n", true).
            "\n-----END PUBLIC KEY-----";
    }

    private function ecJwkToPem(array $jwk): string
    {
        $crv = (string) $jwk['crv'];
        $xBin = $this->base64UrlDecode($jwk['x']);
        $yBin = $this->base64UrlDecode($jwk['y']);

        // Validate coordinate length up front. OpenSSL would reject a
        // wrong-length point at import time but with a generic error;
        // this fails fast with a clear message.
        $expectedLength = self::EC_COORDINATE_LENGTHS[$crv];
        if (strlen($xBin) !== $expectedLength || strlen($yBin) !== $expectedLength) {
            throw new \Exception("Coordenadas EC para {$crv} deben ser exactamente {$expectedLength} bytes; se recibió x=".strlen($xBin).', y='.strlen($yBin));
        }

        // Uncompressed EC point format: 0x04 || x || y.
        $point = "\x04".$xBin.$yBin;

        // RFC 5480 §2.1.1 — AlgorithmIdentifier for EC:
        //   SEQUENCE { OID id-ecPublicKey (1.2.840.10045.2.1),
        //              OID <namedCurveId> }
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

    private function base64UrlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=', STR_PAD_RIGHT);

        return base64_decode($padded);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function lazyCleanup(): void
    {
        $lastCleanup = Cache::get('dpop_nonces_cleanup_date', 0);

        if ($lastCleanup < now()->startOfDay()->timestamp) {
            DB::table('dpop_nonces')
                ->where('expires_at', '<', now())
                ->limit(1000)
                ->delete();

            Cache::put('dpop_nonces_cleanup_date', now()->startOfDay()->timestamp, 86400);
        }
    }
}
