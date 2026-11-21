<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * Best-effort tracker for the alg of the proof currently being
     * verified. Set in `validateDpopProof()` from a header the caller has
     * already parsed, so the catch handlers (which sit OUTSIDE the
     * try/catch) can emit a metric with the real alg even when the
     * verifier throws on a later step (bad signature, thumbprint
     * mismatch, replay, etc.). Reset to null on entry.
     */
    private ?string $lastSeenAlg = null;

    /**
     * Flag set by `validateDpopProof()` when it has already emitted the
     * `alg_not_in_allowlist` metric for the current verification. The
     * outer catch handler in `verify()` / `verifyTokenRequest()` MUST
     * skip its own emission when this flag is true, otherwise the cutover
     * dashboard sees two `dpop_proof_verified` lines per rejected proof
     * — a regression caught by the scoped re-review (finding A, critical).
     */
    private bool $metricAlreadyEmitted = false;

    /**
     * Fallback allowlist when the live config has nothing usable.
     * Source of truth for the dual-allowlist default. Kept in sync
     * manually with `SsoConfig::DEFAULT_DPOP_ALLOWED_ALGS` on the
     * sso-server side (the two live in different packages, so a shared
     * constant is not possible); the cutover runbook
     * (back/docs/DPOP_CUTOVER_RUNBOOK.md) treats the two as a single
     * config surface that must be flipped together.
     */
    public const DEFAULT_DPOP_ALLOWED_ALGS = ['ES256', 'RS256'];

    /**
     * Algorithms accepted in the DPoP proof header during the gradual
     * RSA-2048 → ECDSA P-256 migration. Source of truth lives in
     * config('jwt.dpop_allowed_algs') so the allowlist can be tightened
     * to ['ES256'] via config (or `DPOP_ALLOWED_ALGS=ES256` in env) when
     * the cutover metric shows < 1% RS256 — see docs/DPOP_CUTOVER_RUNBOOK.md.
     * LBDS-258 originally hard-coded this to ['RS256', 'ES256'].
     */
    private function supportedAlgs(): array
    {
        $configured = config('jwt.dpop_allowed_algs');

        return is_array($configured) && $configured !== []
            ? array_values(array_unique(array_map('strval', $configured)))
            : self::DEFAULT_DPOP_ALLOWED_ALGS;
    }

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
        // Reset the dedupe flag AND the alg tracker at the entry-point so
        // a single request that somehow runs multiple verifications
        // (re-review finding D) starts with a clean slate. Mirrors the
        // pattern in DpopVerifier::verify() (which resets lastSeenAlg
        // at the entry-point, not inside the validation method).
        $this->metricAlreadyEmitted = false;
        $this->lastSeenAlg = null;

        if (! $accessToken) {
            return response()->json(
                ApiError::topLevel(ApiError::AUTH_TOKEN_MISSING, 'Token de acceso requerido'),
                401
            );
        }

        if (! $dpopHeader) {
            return response()->json(
                ApiError::topLevel(ApiError::DPOP_INCOMPLETE_JWK, 'DPoP proof requerido para tokens vinculados'),
                401
            );
        }

        try {
            $parsedAccessToken = $this->jwtConfig->parser()->parse($accessToken);
            $cnf = $parsedAccessToken->claims()->get('cnf');

            if (! $cnf || ! isset($cnf['jkt'])) {
                return response()->json(
                    ApiError::topLevel(ApiError::DPOP_INCOMPLETE_JWK, 'El token no está vinculado a una clave DPoP'),
                    401
                );
            }

            $expectedJkt = $cnf['jkt'];

            $dpopToken = $this->jwtConfig->parser()->parse($dpopHeader);

            // Extract the alg from the header BEFORE running the full
            // validation. This is best-effort: if the proof is so malformed
            // that the alg cannot be read, the catch below emits with
            // alg=unknown and reason=parse_failed. Every other rejection
            // path (signature, thumbprint, claims, replay) now reports the
            // real alg so the cutover dashboard can aggregate by alg.
            $alg = $this->extractAlgFromHeader($dpopToken);

            $this->validateDpopProof($dpopToken, $request, $expectedJkt, $accessToken, true, $alg);

        } catch (\Exception $e) {
            // `validateDpopProof()` already emits the metric for the
            // allowlist-reject path (and sets metricAlreadyEmitted).
            // The catch below covers every OTHER failure (bad signature,
            // thumbprint mismatch, missing claims, replay, etc.) so the
            // cutover dashboard reflects all rejections — but only once
            // per verification. Re-review finding A: without the flag,
            // the dashboard saw double `dpop_proof_verified` lines on
            // every allowlist-reject and the `rejected` counter was
            // inflated by 2x.
            if (! $this->metricAlreadyEmitted) {
                $alg = $this->lastSeenAlg ?? 'unknown';
                $this->emitDpopMetric('rejected', $alg, [
                    'reason' => 'verification_failed',
                    'message' => $e->getMessage(),
                ]);
            }

            return response()->json(
                ApiError::topLevel(ApiError::DPOP_UNSUPPORTED_JWK, 'DPoP proof inválido: '.$e->getMessage()),
                401
            );
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
        // Reset the dedupe flag AND the alg tracker at the entry-point
        // (re-review finding D) — mirrors verify() above.
        $this->metricAlreadyEmitted = false;
        $this->lastSeenAlg = null;

        if (! $dpopHeader) {
            return response()->json(
                ApiError::topLevel(ApiError::DPOP_INCOMPLETE_JWK, 'DPoP proof requerido para tokens vinculados'),
                401
            );
        }

        try {
            $dpopToken = $this->jwtConfig->parser()->parse($dpopHeader);

            $alg = $this->extractAlgFromHeader($dpopToken);

            $this->validateDpopProof($dpopToken, $request, $expectedJkt, $accessToken, false, $alg);

        } catch (\Exception $e) {
            // Same dedupe contract as verify() — see metricAlreadyEmitted
            // docblock (re-review finding A).
            if (! $this->metricAlreadyEmitted) {
                $alg = $this->lastSeenAlg ?? 'unknown';
                $this->emitDpopMetric('rejected', $alg, [
                    'reason' => 'verification_failed',
                    'message' => $e->getMessage(),
                ]);
            }

            return response()->json(
                ApiError::topLevel(ApiError::DPOP_UNSUPPORTED_JWK, 'DPoP proof inválido: '.$e->getMessage()),
                401
            );
        }

        return null;
    }

    /**
     * Read just the `alg` header from an already-parsed DPoP token. Used
     * by the verify() entry points to capture the alg before running
     * validation, so the post-validation catch can emit a metric with the
     * real alg instead of `unknown`. Returns `null` if the header is
     * missing or unparsable; the caller treats null as `unknown` for the
     * metric and lets the real validation throw on the missing header.
     */
    private function extractAlgFromHeader($dpopToken): ?string
    {
        $header = $dpopToken->headers();
        $alg = $header->get('alg');

        return $alg === null ? null : (string) $alg;
    }

    /**
     * Shared proof validation used by verify() and verifyTokenRequest().
     *
     * @param  bool  $requireAth  verify() requires ath (access-token binding);
     *                            the token endpoint accepts a proof without it.
     */
    private function validateDpopProof($dpopToken, Request $request, string $expectedJkt, ?string $accessToken, bool $requireAth, ?string $alg = null): void
    {
        // lastSeenAlg and metricAlreadyEmitted are reset by the public
        // entry-points verify()/verifyTokenRequest(). Keeping the reset
        // here too would clobber an alg that an outer caller already
        // captured; not resetting it (the previous behavior) left stale
        // state from a prior verification in the same instance.
        $header = $dpopToken->headers();

        if ($header->get('typ') !== 'dpop+jwt') {
            throw new \Exception('Tipo de token DPoP inválido');
        }

        $alg = $alg ?? (string) $header->get('alg');
        $this->lastSeenAlg = $alg;

        if (! in_array($alg, $this->supportedAlgs(), true)) {
            // Emit the metric for the allowlist-reject path HERE and set
            // the dedupe flag so the outer catch handler does not
            // double-emit (re-review finding A, critical).
            $this->emitDpopMetric('rejected', $alg, [
                'reason' => 'alg_not_in_allowlist',
            ]);
            $this->metricAlreadyEmitted = true;

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

        $inserted = DB::table('dpop_nonces')->insertOrIgnore([
            'jti' => $jti,
            'expires_at' => now()->addMinutes(2),
        ]);

        if ($inserted === 0) {
            throw new \Exception('DPoP proof reutilizado (replay detectado)');
        }

        $this->emitDpopMetric('accepted', $alg, [
            'jkt_prefix' => substr($expectedJkt, 0, 8),
            'htm' => (string) $htm,
        ]);

        $this->lazyCleanup();
    }

    /**
     * Emit a structured log line for the DPoP cutover metric. The runbook
     * (back/docs/DPOP_CUTOVER_RUNBOOK.md) treats the `event=dpop_proof_verified`
     * line as the canonical counter — aggregate by `alg` and `result` to get
     * `dpop_proofs_total{alg,result}`. The verifier stays free of any direct
     * Prometheus / OTLP dependency; the log line is what feeds the dashboard.
     *
     * The emission is best-effort: a logging failure must not turn a valid
     * proof into an invalid one (the verifier's contract is auth, not
     * telemetry). If the configured channel throws, the emission falls back
     * to the `single` channel (file-backed and always present in Laravel)
     * and finally to PHP's `error_log` as the last-resort visibility hook,
     * so a misconfigured pipeline never silently kills the cutover metric.
     *
     * @param  array<string, mixed>  $context
     */
    private function emitDpopMetric(string $result, string $alg, array $context = []): void
    {
        $context['event'] = 'dpop_proof_verified';
        $context['alg'] = $alg;
        $context['result'] = $result;
        $context['allowlist'] = $this->supportedAlgs();

        $primary = config('logging.default') ?? 'stack';

        try {
            Log::channel($primary)->info('dpop_proof_verified', $context);
        } catch (\Throwable $primaryError) {
            try {
                Log::channel('single')->info('dpop_proof_verified', $context + [
                    'metric_primary_channel_error' => $primaryError->getMessage(),
                ]);
            } catch (\Throwable) {
                // Last-resort visibility hook. We never want a broken log
                // pipeline to silently drop every cutover metric, so this
                // is the floor: a single line in PHP's error log that the
                // on-call can grep while the real channel is fixed.
                error_log('dpop_proof_verified '.json_encode($context));
            }
        }
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

        // Normalize and validate coordinate lengths. A valid P-256 coordinate
        // is always 32 bytes. A 31-byte value means the leading 0x00 byte was
        // stripped when the client encoded the JWK (roughly 1 key in 256, and
        // PHP's openssl_pkey_get_details does exactly that). Restoring the
        // padding keeps those proofs verifiable instead of rejecting them,
        // and matches how dowardev/sso-server normalizes the same coordinates.
        $expectedLength = self::EC_COORDINATE_LENGTHS[$crv];
        $xBin = $this->normalizeEcCoordinate($xBin, $expectedLength);
        $yBin = $this->normalizeEcCoordinate($yBin, $expectedLength);

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

    /**
     * Normalize an EC coordinate to the expected length.
     *
     * A valid P-256 coordinate is always 32 bytes. A 31-byte value means the
     * leading 0x00 byte was stripped during the base64url encoding (it
     * happens when the most significant byte of the coordinate is 0x00), so
     * the padding is restored here. Any other length is invalid.
     */
    private function normalizeEcCoordinate(string $coordinate, int $expectedLength): string
    {
        $length = strlen($coordinate);

        if ($length === $expectedLength) {
            return $coordinate;
        }

        if ($length === $expectedLength - 1) {
            return "\x00".$coordinate;
        }

        throw new \Exception("Coordenadas EC para P-256 deben ser exactamente {$expectedLength} bytes; se recibió ".$length);
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
