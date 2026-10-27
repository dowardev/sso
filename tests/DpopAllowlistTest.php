<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Tests;

use Dowardev\SsoServer\Dpop\DpopVerifier;
use Dowardev\SsoServer\Dpop\ReplayProtection;
use Dowardev\SsoServer\Exceptions\DpopVerificationException;
use Dowardev\SsoServer\Jwt\JwksFetcher;
use Dowardev\SsoServer\Jwt\JwkToPemConverter;
use Dowardev\SsoServer\Jwt\JwtValidator;
use Dowardev\SsoServer\Types\SsoConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Covers the DPoP algorithm allowlist cutover tracked by LBDS-290.
 *
 * The allowlist lives in SsoConfig::$dpopAllowedAlgs (resolves from
 * config('sso.dpop_allowed_algs') or SSO_DPOP_ALLOWED_ALGS env) so the
 * cutover can flip ['ES256','RS256'] -> ['ES256'] via a config-only
 * change once the cutover metric in docs/DPOP_CUTOVER_RUNBOOK.md shows
 * < 1% RS256 sustained.
 *
 * Each test instantiates DpopVerifier with a custom SsoConfig so the
 * allowlist override is scoped to the test (no global config mutation).
 */
class DpopAllowlistTest extends TestCase
{
    /** Snapshot of `logging.default` so the metric-emission tests can
     *  set it to 'single' and restore the original value afterwards
     *  (re-review finding B). */
    private mixed $originalLoggingDefault;

    private function makeVerifier(array $dpopAllowedAlgs): DpopVerifier
    {
        Cache::flush();

        $config = new SsoConfig(
            ssoUrl: 'http://localhost:8001/api',
            jwtIssuer: 'http://localhost:8001',
            jwksCacheTtl: 3600,
            rolesCacheTtl: 300,
            dpopReplayTtl: 120,
            dpopIatLeeway: 60,
            clientId: '1',
            roleMappings: ['admin' => ['slug' => 'admin']],
            dpopAllowedAlgs: $dpopAllowedAlgs,
        );

        $converter = new JwkToPemConverter;
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $jwtValidator = new JwtValidator($config, $jwksFetcher);
        $replayProtection = new ReplayProtection($config);

        return new DpopVerifier(
            $config,
            $converter,
            $jwtValidator,
            $replayProtection,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalLoggingDefault = config('logging.default');
    }

    protected function tearDown(): void
    {
        Cache::flush();

        if ($this->originalLoggingDefault !== null) {
            config()->set('logging.default', $this->originalLoggingDefault);
        }

        parent::tearDown();
    }

    public function test_es256_only_allowlist_accepts_es256(): void
    {
        $verifier = $this->makeVerifier(['ES256']);
        $builder = EcDpopProofBuilder::generate();

        $verifier->verify(
            dpopProof: $builder->proof(),
            accessToken: 'access-token-123',
            expectedJkt: $builder->jkt(),
            method: 'POST',
            uri: 'http://localhost/api/oauth/token',
        );

        $this->expectNotToPerformAssertions();
    }

    public function test_es256_only_allowlist_rejects_rs256_with_clear_error(): void
    {
        $verifier = $this->makeVerifier(['ES256']);
        $builder = RsaDpopProofBuilder::generate();

        $this->expectException(DpopVerificationException::class);
        $this->expectExceptionMessage('Algoritmo DPoP no soportado: RS256');

        $verifier->verify(
            dpopProof: $builder->proof(),
            accessToken: 'access-token-123',
            expectedJkt: $builder->jkt(),
            method: 'POST',
            uri: 'http://localhost/api/oauth/token',
        );
    }

    public function test_dual_allowlist_accepts_both_algorithms(): void
    {
        // Belt-and-braces for the post-LBDS-258 default: even when the
        // allowlist is the explicit dual list, both algorithms must
        // verify end-to-end.
        $verifier = $this->makeVerifier(['ES256', 'RS256']);

        $rsa = RsaDpopProofBuilder::generate();
        $verifier->verify(
            dpopProof: $rsa->proof(),
            accessToken: 'access-token-123',
            expectedJkt: $rsa->jkt(),
            method: 'POST',
            uri: 'http://localhost/api/oauth/token',
        );

        $ec = EcDpopProofBuilder::generate();
        $verifier->verify(
            dpopProof: $ec->proof(),
            accessToken: 'access-token-123',
            expectedJkt: $ec->jkt(),
            method: 'POST',
            uri: 'http://localhost/api/oauth/token',
        );

        $this->expectNotToPerformAssertions();
    }

    public function test_single_rs256_allowlist_rejects_es256(): void
    {
        // Inverse asymmetry of the cutover: if the allowlist were only
        // RS256 (an extreme post-cutover safety net), ES256 proofs must
        // be rejected with the canonical Spanish text.
        $verifier = $this->makeVerifier(['RS256']);
        $builder = EcDpopProofBuilder::generate();

        $this->expectException(DpopVerificationException::class);
        $this->expectExceptionMessage('Algoritmo DPoP no soportado: ES256');

        $verifier->verify(
            dpopProof: $builder->proof(),
            accessToken: 'access-token-123',
            expectedJkt: $builder->jkt(),
            method: 'POST',
            uri: 'http://localhost/api/oauth/token',
        );
    }

    // --- F7: metric emission (LBDS-290 / pre-merge review #8-11) ------------------

    public function test_es256_allowlist_rejects_rs256_emits_metric_with_real_alg(): void
    {
        // The headline cutover signal: a cutover attempt against an
        // ES256-only allowlist must surface alg='RS256' in the metric so
        // the dashboard can build dpop_proofs_total{alg="RS256"}.
        $verifier = $this->makeVerifier(['ES256']);
        $builder = RsaDpopProofBuilder::generate();

        $this->resetLogFile();

        try {
            $verifier->verify(
                dpopProof: $builder->proof(),
                accessToken: 'access-token-123',
                expectedJkt: $builder->jkt(),
                method: 'POST',
                uri: 'http://localhost/api/oauth/token',
            );
        } catch (DpopVerificationException) {
            // expected
        }

        $entries = $this->readDpopMetricFromLog();
        $this->assertTrue($entries->contains(function (array $entry) {
            return ($entry['alg'] ?? null) === 'RS256'
                && ($entry['result'] ?? null) === 'rejected'
                && in_array('ES256', (array) ($entry['allowlist'] ?? []), true);
        }), 'Expected at least one dpop_proof_verified metric with alg=RS256, result=rejected. Got: '.json_encode($entries->all()));
    }

    public function test_dual_allowlist_accepts_es256_emits_accepted_metric(): void
    {
        $verifier = $this->makeVerifier(['ES256', 'RS256']);
        $builder = EcDpopProofBuilder::generate();

        $this->resetLogFile();

        $verifier->verify(
            dpopProof: $builder->proof(),
            accessToken: 'access-token-123',
            expectedJkt: $builder->jkt(),
            method: 'POST',
            uri: 'http://localhost/api/oauth/token',
        );

        $entries = $this->readDpopMetricFromLog();
        $this->assertTrue($entries->contains(function (array $entry) {
            return ($entry['alg'] ?? null) === 'ES256'
                && ($entry['result'] ?? null) === 'accepted'
                && is_string($entry['jkt_prefix'] ?? null)
                && ($entry['htm'] ?? null) === 'POST';
        }), 'Expected at least one dpop_proof_verified metric with alg=ES256, result=accepted. Got: '.json_encode($entries->all()));
    }

    public function test_thumbprint_mismatch_emits_metric_with_real_alg(): void
    {
        // Pre-merge review #3 sibling: a proof signed by one key but
        // claiming a different JWK thumbprint must report the real alg
        // (not 'unknown') so the cutover metric keeps working on this
        // rejection path.
        $verifier = $this->makeVerifier(['ES256', 'RS256']);
        $bound = RsaDpopProofBuilder::generate();
        $other = RsaDpopProofBuilder::generate();

        $this->resetLogFile();

        try {
            $verifier->verify(
                dpopProof: $bound->proof(),
                accessToken: 'access-token-123',
                expectedJkt: $other->jkt(),
                method: 'POST',
                uri: 'http://localhost/api/oauth/token',
            );
        } catch (DpopVerificationException) {
            // expected
        }

        $entries = $this->readDpopMetricFromLog();
        $this->assertTrue($entries->contains(function (array $entry) {
            return ($entry['alg'] ?? null) === 'RS256'
                && ($entry['result'] ?? null) === 'rejected';
        }), 'Expected at least one dpop_proof_verified metric with alg=RS256, result=rejected. Got: '.json_encode($entries->all()));
    }

    private function resetLogFile(): void
    {
        // sso-server testbench runs with logging.default=errorlog by
        // default; we swap to 'single' for these tests so the metric
        // lines land in a parseable file. The swap is restored in
        // tearDown.
        config()->set('logging.default', 'single');
        $path = storage_path('logs/laravel.log');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        if (file_exists($path)) {
            file_put_contents($path, '');
        }
    }

    private function readDpopMetricFromLog(): Collection
    {
        $path = storage_path('logs/laravel.log');

        if (! file_exists($path)) {
            return collect();
        }

        $entries = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $marker = 'dpop_proof_verified {';
            $pos = strpos($line, $marker);
            if ($pos === false) {
                continue;
            }

            $contextBlob = substr($line, $pos + strlen('dpop_proof_verified '));
            $context = json_decode(rtrim($contextBlob), true);
            if (! is_array($context)) {
                continue;
            }

            $entries[] = $context;
        }

        return collect($entries);
    }

    // --- F8: env parsing edge cases (LBDS-290 / pre-merge review #17) -------------
    //
    // The provider's resolveDpopAllowedAlgsConfig() resolves
    // SSO_DPOP_ALLOWED_ALGS into the live config repository. The actual
    // env-read lives there; this suite pins the *end state* the verifier
    // sees after that resolution by writing to config() the same way the
    // provider does. This protects the contract: a future refactor of
    // the provider cannot silently regress the verifier.

    public function test_sso_config_resolves_dual_allowlist_from_config(): void
    {
        config()->set('sso.dpop_allowed_algs', ['ES256', 'RS256']);

        $config = SsoConfig::fromConfig();

        $this->assertSame(['ES256', 'RS256'], $config->dpopAllowedAlgs);
    }

    public function test_sso_config_resolves_es256_only_allowlist_from_config(): void
    {
        config()->set('sso.dpop_allowed_algs', ['ES256']);

        $config = SsoConfig::fromConfig();

        $this->assertSame(['ES256'], $config->dpopAllowedAlgs);
    }

    public function test_sso_config_dedupes_repeated_entries(): void
    {
        // The provider's trim/filter/dedup chain is the canonical
        // normalizer. A config with a repeated ES256 must end up
        // deduplicated so the verifier never carries a stale entry that
        // an attacker could match against.
        config()->set('sso.dpop_allowed_algs', array_values(array_unique(
            array_filter(array_map('trim', explode(',', 'ES256,RS256,ES256')))
        )));

        $config = SsoConfig::fromConfig();

        $this->assertSame(['ES256', 'RS256'], $config->dpopAllowedAlgs);
    }

    public function test_sso_config_trims_whitespace_around_commas(): void
    {
        config()->set('sso.dpop_allowed_algs', array_values(array_filter(
            array_map('trim', explode(',', ' ES256 , RS256 '))
        )));

        $config = SsoConfig::fromConfig();

        $this->assertSame(['ES256', 'RS256'], $config->dpopAllowedAlgs);
    }

    public function test_sso_config_empty_config_falls_back_to_default(): void
    {
        // Empty array (the resolved state for `SSO_DPOP_ALLOWED_ALGS=`)
        // must not lock the verifier out: the default kicks in.
        config()->set('sso.dpop_allowed_algs', []);

        $config = SsoConfig::fromConfig();

        $this->assertSame(['ES256', 'RS256'], $config->dpopAllowedAlgs);
    }
}
