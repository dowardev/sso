<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Symfony\Component\HttpFoundation\Response;

class VerifyDpopProof
{
    private Configuration $jwtConfig;

    public function __construct()
    {
        $this->jwtConfig = Configuration::forAsymmetricSigner(
            new Sha256(),
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
        if (!$accessToken) {
            return response()->json([
                'message' => 'Token de acceso requerido'
            ], 401);
        }

        if (!$dpopHeader) {
            return response()->json([
                'message' => 'DPoP proof requerido para tokens vinculados'
            ], 401);
        }

        try {
            $parsedAccessToken = $this->jwtConfig->parser()->parse($accessToken);
            $cnf = $parsedAccessToken->claims()->get('cnf');

            if (!$cnf || !isset($cnf['jkt'])) {
                return response()->json([
                    'message' => 'El token no está vinculado a una clave DPoP'
                ], 401);
            }

            $expectedJkt = $cnf['jkt'];

            $dpopToken = $this->jwtConfig->parser()->parse($dpopHeader);

            $this->validateDpopProof($dpopToken, $accessToken, $request, $expectedJkt);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'DPoP proof inválido: ' . $e->getMessage()
            ], 401);
        }

        return null;
    }

    private function validateDpopProof($dpopToken, string $accessToken, Request $request, string $expectedJkt): void
    {
        $header = $dpopToken->headers();

        if ($header->get('typ') !== 'dpop+jwt') {
            throw new \Exception('Tipo de token DPoP inválido');
        }

        $alg = $header->get('alg');
        if ($alg !== 'RS256') {
            throw new \Exception('Algoritmo DPoP no soportado');
        }

        $jwkHeader = $header->get('jwk');
        if (!$jwkHeader) {
            throw new \Exception('Falta la clave pública JWK en el header DPoP');
        }

        $jwkArray = (array) $jwkHeader;
        if (!isset($jwkArray['kty']) || !isset($jwkArray['n']) || !isset($jwkArray['e'])) {
            throw new \Exception('JWK del DPoP proof incompleto: faltan campos requeridos');
        }

        if (($jwkArray['kty'] ?? '') !== 'RSA') {
            throw new \Exception('Tipo de clave JWK no soportado: se requiere RSA');
        }

        $jktFromProof = $this->generateJWKThumbprint($jwkArray);
        if (!hash_equals($expectedJkt, $jktFromProof)) {
            throw new \Exception('La huella digital JWK del DPoP proof no coincide con la vinculada al token');
        }

        $dpopPublicKey = $this->jwkToPem($jwkArray);

        $verificationKey = InMemory::plainText($dpopPublicKey);
        $signer = new Sha256();
        $constraint = new \Lcobucci\JWT\Validation\Constraint\SignedWith($signer, $verificationKey);

        if (!$this->jwtConfig->validator()->validate($dpopToken, $constraint)) {
            throw new \Exception('Firma del DPoP proof inválida');
        }

        $payload = $dpopToken->claims();

        $htm = $payload->get('htm');
        $htu = $payload->get('htu');
        $iat = $payload->get('iat');
        $jti = $payload->get('jti');
        $ath = $payload->get('ath');

        if (!$htm || !$htu || !$iat || !$jti || !$ath) {
            throw new \Exception('DPoP proof incompleto: faltan claims requeridos (htm, htu, iat, jti, ath)');
        }

        if ($htm !== $request->method()) {
            throw new \Exception('Método HTTP no coincide en DPoP proof');
        }

        $baseUrl = $request->getSchemeAndHttpHost() . $request->getBaseUrl();
        $expectedHtu = $baseUrl . $request->getPathInfo();

        if ($htu !== $expectedHtu && $htu !== $request->fullUrl()) {
            throw new \Exception('URI HTTP no coincide en DPoP proof');
        }

        $now = time();
        $iatTimestamp = $iat instanceof \DateTimeInterface ? $iat->getTimestamp() : (int) $iat;
        if ($iatTimestamp > $now + 60 || $iatTimestamp < $now - 60) {
            throw new \Exception('Timestamp del DPoP proof fuera de rango');
        }

        $tokenHash = rtrim(strtr(base64_encode(hash('sha256', $accessToken, true)), '+/', '-_'), '=');
        if (!hash_equals($tokenHash, $ath)) {
            throw new \Exception('Hash del token de acceso no coincide en DPoP proof');
        }

        DB::insert('INSERT IGNORE INTO dpop_nonces (jti, expires_at) VALUES (?, ?)', [
            $jti,
            now()->addMinutes(2),
        ]);

        if (DB::getPdo()->rowCount() === 0) {
            throw new \Exception('DPoP proof reutilizado (replay detectado)');
        }

        $this->lazyCleanup();
    }

    private function generateJWKThumbprint(array $jwk): string
    {
        $jwkString = json_encode([
            'e' => $jwk['e'] ?? null,
            'kty' => $jwk['kty'] ?? 'RSA',
            'n' => $jwk['n'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return rtrim(strtr(base64_encode(hash('sha256', $jwkString, true)), '+/', '-_'), '=');
    }

    private function jwkToPem(array $jwk): string
    {
        $n = $this->base64UrlDecode($jwk['n']);
        $e = $this->base64UrlDecode($jwk['e']);

        $modulus = '
-----BEGIN PUBLIC KEY-----
' . wordwrap($this->base64UrlEncode($n . $e), 64, "\n", true) . '
-----END PUBLIC KEY-----';

        $keyDetails = openssl_pkey_get_details(openssl_pkey_get_public($modulus));
        if ($keyDetails === false) {
            $nBin = $this->base64UrlDecode($jwk['n']);
            $eBin = $this->base64UrlDecode($jwk['e']);

            $asn1 = $this->encodeASN1Sequence(
                $this->encodeASN1Sequence(
                    $this->encodeASN1Sequence(
                        $this->encodeASN1OID('1.2.840.113549.1.1.1') .
                        $this->encodeASN1Null()
                    ) .
                    $this->encodeASN1BitString(
                        $this->encodeASN1Sequence(
                            $this->encodeASN1Integer($nBin) .
                            $this->encodeASN1Integer($eBin)
                        )
                    )
                )
            );

            $pem = "-----BEGIN PUBLIC KEY-----\n" .
                wordwrap(base64_encode($asn1), 64, "\n", true) .
                "\n-----END PUBLIC KEY-----";

            return $pem;
        }

        return $keyDetails['key'];
    }

    private function encodeASN1Integer(string $value): string
    {
        if (ord($value[0]) & 0x80) {
            $value = "\x00" . $value;
        }
        return "\x02" . $this->encodeASN1Length(strlen($value)) . $value;
    }

    private function encodeASN1Sequence(string $value): string
    {
        return "\x30" . $this->encodeASN1Length(strlen($value)) . $value;
    }

    private function encodeASN1BitString(string $value): string
    {
        $value = "\x00" . $value;
        return "\x03" . $this->encodeASN1Length(strlen($value)) . $value;
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
                $temp = chr($val % 128) . $temp;
                $val = intdiv($val, 128);
                while ($val > 0) {
                    $temp = chr(($val % 128) | 0x80) . $temp;
                    $val = intdiv($val, 128);
                }
                $encoded .= $temp;
            }
        }
        return "\x06" . $this->encodeASN1Length(strlen($encoded)) . $encoded;
    }

    private function encodeASN1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $lengthBytes = '';
        $temp = $length;
        while ($temp > 0) {
            $lengthBytes = chr($temp & 0xFF) . $lengthBytes;
            $temp >>= 8;
        }
        return chr(0x80 | strlen($lengthBytes)) . $lengthBytes;
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