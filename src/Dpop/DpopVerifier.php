<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Dpop;

use Dowardev\SsoServer\Exceptions\DpopVerificationException;
use Dowardev\SsoServer\Jwt\JwkToPemConverter;
use Dowardev\SsoServer\Jwt\JwtValidator;
use Dowardev\SsoServer\Types\SsoConfig;
use Illuminate\Support\Facades\Log;
use Lcobucci\JWT\Token;

class DpopVerifier
{
    /**
     * Best-effort tracker for the alg of the proof currently being
     * verified. Set in `validateTypeAndAlgorithm()` from a header the
     * caller has already parsed, so the catch handlers (which sit OUTSIDE
     * the try/catch) can emit a metric with the real alg even when a
     * later step (signature, thumbprint, replay) throws. Reset to null on
     * entry.
     */
    private ?string $lastSeenAlg = null;

    public function __construct(
        private readonly SsoConfig $config,
        private readonly JwkToPemConverter $converter,
        private readonly JwtValidator $jwtValidator,
        private readonly ReplayProtection $replayProtection,
    ) {}

    public function verify(
        string $dpopProof,
        string $accessToken,
        string $expectedJkt,
        string $method,
        string $uri,
    ): void {
        $this->lastSeenAlg = null;

        try {
            $dpopToken = $this->jwtValidator->parse($dpopProof);

            $alg = $this->validateTypeAndAlgorithm($dpopToken);

            $jwkArray = $this->extractAndValidateJwk($dpopToken, $alg);

            $this->validateThumbprint($jwkArray, $expectedJkt);

            $dpopPublicKeyPem = $this->converter->convert($jwkArray);
            $this->jwtValidator->validateDpopProofSignature($dpopProof, $dpopPublicKeyPem);

            $this->validateClaims($dpopToken, $accessToken, $method, $uri);

            $jti = $this->jwtValidator->getClaim($dpopToken, 'jti');
            $this->replayProtection->checkAndStore((string) $jti);

            $this->emitMetric('accepted', $alg, [
                'jkt_prefix' => substr($expectedJkt, 0, 8),
                'htm' => $method,
            ]);
        } catch (DpopVerificationException $e) {
            $alg = $this->lastSeenAlg ?? 'unknown';
            $this->emitMetric('rejected', $alg, [
                'reason' => $e->getErrorCode(),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        } catch (\Exception $e) {
            $alg = $this->lastSeenAlg ?? 'unknown';
            $this->emitMetric('rejected', $alg, [
                'reason' => 'exception',
                'message' => $e->getMessage(),
            ]);

            throw DpopVerificationException::invalidProof($e->getMessage());
        }
    }

    private function validateTypeAndAlgorithm(Token $dpopToken): string
    {
        $typ = $this->jwtValidator->getHeader($dpopToken, 'typ');
        if ($typ !== 'dpop+jwt') {
            throw DpopVerificationException::invalidType();
        }

        $alg = (string) $this->jwtValidator->getHeader($dpopToken, 'alg');

        // Set the tracker BEFORE the allowlist check so the catch can emit
        // a metric with the real alg even when the proof is rejected here.
        $this->lastSeenAlg = $alg;

        if (! in_array($alg, $this->config->dpopAllowedAlgs, true)) {
            throw DpopVerificationException::unsupportedAlgorithm($alg);
        }

        return $alg;
    }

    private function extractAndValidateJwk(Token $dpopToken, string $alg): array
    {
        $jwkHeader = $this->jwtValidator->getHeader($dpopToken, 'jwk');
        if (! $jwkHeader) {
            throw DpopVerificationException::missingJwk();
        }

        $jwkArray = (array) $jwkHeader;
        $kty = $jwkArray['kty'] ?? '';

        // alg/kty cross-check: an attacker must not be able to mix algorithms
        // and key types. The signer selection (ECDSA vs RSA) and the PEM
        // generation (EC vs RSA) both depend on these being consistent —
        // a mismatch is rejected with a clear error before the signature
        // verification step.
        if ($alg === 'ES256' && $kty !== 'EC') {
            throw DpopVerificationException::unsupportedAlgorithm("alg ES256 requires kty EC, got {$kty}");
        }
        if ($alg === 'RS256' && $kty !== 'RSA') {
            throw DpopVerificationException::unsupportedAlgorithm("alg RS256 requires kty RSA, got {$kty}");
        }

        if ($kty === 'EC') {
            if (! isset($jwkArray['crv'], $jwkArray['x'], $jwkArray['y'])) {
                throw DpopVerificationException::incompleteJwk();
            }
            if ($jwkArray['crv'] !== 'P-256') {
                throw DpopVerificationException::unsupportedAlgorithm('EC '.$jwkArray['crv']);
            }
        } elseif ($kty === 'RSA') {
            if (! isset($jwkArray['n'], $jwkArray['e'])) {
                throw DpopVerificationException::incompleteJwk();
            }
        } else {
            throw DpopVerificationException::unsupportedAlgorithm($kty ?: 'unknown');
        }

        return $jwkArray;
    }

    private function validateThumbprint(array $jwkArray, string $expectedJkt): void
    {
        $jktFromProof = JwkThumbprint::compute($jwkArray);
        if (! hash_equals($expectedJkt, $jktFromProof)) {
            throw DpopVerificationException::thumbprintMismatch();
        }
    }

    private function validateClaims(
        Token $dpopToken,
        string $accessToken,
        string $method,
        string $uri,
    ): void {
        $htm = $this->jwtValidator->getClaim($dpopToken, 'htm');
        $htu = $this->jwtValidator->getClaim($dpopToken, 'htu');
        $iat = $this->jwtValidator->getClaim($dpopToken, 'iat');
        $jti = $this->jwtValidator->getClaim($dpopToken, 'jti');
        $ath = $this->jwtValidator->getClaim($dpopToken, 'ath');

        if (! $htm || ! $htu || ! $iat || ! $jti || ! $ath) {
            throw DpopVerificationException::missingClaims();
        }

        if ($htm !== $method) {
            throw DpopVerificationException::methodMismatch();
        }

        if ($htu !== $uri) {
            throw DpopVerificationException::uriMismatch();
        }

        $now = time();
        $iatTimestamp = $iat instanceof \DateTimeInterface ? $iat->getTimestamp() : (int) $iat;
        if ($iatTimestamp > $now + $this->config->dpopIatLeeway || $iatTimestamp < $now - $this->config->dpopIatLeeway) {
            throw DpopVerificationException::timestampOutOfRange();
        }

        $tokenHash = JwkToPemConverter::base64UrlEncode(hash('sha256', $accessToken, true));
        if (! hash_equals($tokenHash, $ath)) {
            throw DpopVerificationException::accessTokenHashMismatch();
        }
    }

    /**
     * Emit a structured log line for the DPoP cutover metric. The runbook
     * (back/docs/DPOP_CUTOVER_RUNBOOK.md) treats `event=dpop_proof_verified`
     * as the canonical counter — aggregate by `alg` and `result` to get
     * `dpop_proofs_total{alg,result}`. Stays free of any direct Prometheus /
     * OTLP dependency; the log line is what feeds the dashboard.
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
    private function emitMetric(string $result, string $alg, array $context = []): void
    {
        $context['event'] = 'dpop_proof_verified';
        $context['alg'] = $alg;
        $context['result'] = $result;
        $context['allowlist'] = $this->config->dpopAllowedAlgs;

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
}
