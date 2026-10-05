<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\SsoClient;
use App\Models\User;
use App\Services\OAuth2Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\DpopProofBuilder;
use Tests\TestCase;

/**
 * Covers the DPoP algorithm allowlist cutover tracked by LBDS-290.
 *
 * The allowlist lives in config('jwt.dpop_allowed_algs') so the cutover can
 * flip ['ES256','RS256'] -> ['ES256'] via a config-only change once the
 * cutover metric in docs/DPOP_CUTOVER_RUNBOOK.md shows < 1% RS256 sustained.
 *
 * Tests that mutate the config do it inside their own method and reset it in
 * tearDown so other suites (notably DpopRefreshTokenTest) keep seeing the
 * resolver default. RefreshDatabase resets the DB but not Laravel's
 * repository-bound config.
 */
class DpopAllowlistTest extends TestCase
{
    use RefreshDatabase;

    /** Snapshot of the allowlist at setUp time so tearDown can restore it. */
    private mixed $originalAllowlist;

    /** Snapshot of `logging.default` so the metric-emission tests can
     *  set it to 'single' and restore the original value afterwards. */
    private mixed $originalLoggingDefault;

    protected User $user;

    protected SsoClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'dpop-allowlist@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->client = SsoClient::create([
            'name' => 'Test App',
            'client_id' => 'test-app',
            'redirect_url' => 'https://app.example.com/auth/callback',
            'allowed_origins' => ['https://app.example.com'],
            'grant_types' => ['authorization_code'],
            'active' => true,
        ]);

        $this->originalAllowlist = config('jwt.dpop_allowed_algs');
        $this->originalLoggingDefault = config('logging.default');
    }

    protected function tearDown(): void
    {
        config()->set('jwt.dpop_allowed_algs', $this->originalAllowlist);
        config()->set('logging.default', $this->originalLoggingDefault);

        parent::tearDown();
    }

    public function test_es256_allowlist_accepts_es256_proof(): void
    {
        // Simulate the post-cutover world: only ES256 is allowed. ES256
        // proofs must continue to verify.
        config()->set('jwt.dpop_allowed_algs', ['ES256']);

        $builder = DpopProofBuilder::generateEc();
        $refreshToken = $this->boundRefreshToken($builder);

        $this->refresh($refreshToken, $builder->proof())->assertOk();
    }

    public function test_es256_allowlist_rejects_rs256_proof(): void
    {
        // The flip side of the cutover: an RS256 proof sent after the
        // allowlist is restricted to ['ES256'] must be rejected. The
        // controller short-circuits on the refresh-token lookup failure
        // (DPoP runs inside handleRefreshToken), so we only assert the
        // status code here; the verifier's own "Algoritmo DPoP no
        // soportado" message is covered by the sso-server unit test in
        // packages/sso-server/tests/DpopVerifierTest.
        config()->set('jwt.dpop_allowed_algs', ['ES256']);

        $builder = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($builder);

        $this->refresh($refreshToken, $builder->proof())->assertUnauthorized();
    }

    public function test_dual_allowlist_accepts_both_rs256_and_es256(): void
    {
        // The default post-LBDS-258 state — the allowlist is permissive
        // so legacy RSA bindings keep verifying while new clients sign
        // with ES256. Both algorithms must complete the refresh.
        config()->set('jwt.dpop_allowed_algs', ['ES256', 'RS256']);

        $rsa = DpopProofBuilder::generate();
        $rsaToken = $this->boundRefreshToken($rsa);
        $this->refresh($rsaToken, $rsa->proof())->assertOk();

        $ec = DpopProofBuilder::generateEc();
        $ecToken = $this->boundRefreshToken($ec);
        $this->refresh($ecToken, $ec->proof())->assertOk();
    }

    public function test_empty_allowlist_falls_back_to_default(): void
    {
        // A misconfigured empty allowlist must NOT lock every proof out:
        // the verifier falls back to ['ES256','RS256'] so production stays
        // available while ops fix the config.
        config()->set('jwt.dpop_allowed_algs', []);

        $rsa = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($rsa);

        $this->refresh($refreshToken, $rsa->proof())->assertOk();
    }

    public function test_null_allowlist_falls_back_to_default(): void
    {
        // Pre-merge review #16: `null` and `[]` reach supportedAlgs() via
        // different code paths (config repo default vs explicit empty
        // array). Both must produce the same dual-allowlist fallback so
        // a typo in DPOP_ALLOWED_ALGS=undefined never locks prod out.
        config()->set('jwt.dpop_allowed_algs', null);

        $rsa = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($rsa);

        $this->refresh($refreshToken, $rsa->proof())->assertOk();
    }

    // --- F7: metric emission (LBDS-290 / pre-merge review #8-11) ------------------
    //
    // The runbook treats `event=dpop_proof_verified alg=... result=...` as
    // the canonical counter. These tests pin the emission shape so the
    // dashboard query in docs/DPOP_CUTOVER_RUNBOOK.md §1 keeps working
    // when the verifier is refactored.

    public function test_es256_allowlist_rejects_rs256_proof_emits_metric_with_real_alg(): void
    {
        // The headline cutover signal: an RS256 proof with an
        // ES256-only allowlist must produce a metric line where alg is
        // 'RS256' (not 'unknown'), so the dashboard's
        // dpop_proofs_total{alg="RS256",result="rejected"} query works.
        config()->set('jwt.dpop_allowed_algs', ['ES256']);

        $this->resetLogFile();

        $builder = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($builder);

        $this->refresh($refreshToken, $builder->proof())->assertUnauthorized();

        $entries = $this->readDpopMetricFromLog();
        if ($entries->isEmpty()) {
            $raw = file_exists(storage_path('logs/laravel.log'))
                ? file_get_contents(storage_path('logs/laravel.log'))
                : 'FILE NOT FOUND';
            $this->fail("Captured 0 dpop_proof_verified entries. Raw log:\n".$raw);
        }
        $this->assertTrue($entries->contains(function (array $entry) {
            return ($entry['alg'] ?? null) === 'RS256'
                && ($entry['result'] ?? null) === 'rejected'
                && ($entry['reason'] ?? null) === 'alg_not_in_allowlist'
                && in_array('ES256', (array) ($entry['allowlist'] ?? []), true);
        }), 'Expected at least one dpop_proof_verified metric with alg=RS256, result=rejected, reason=alg_not_in_allowlist. Got: '.json_encode($entries->all()));
    }

    public function test_dual_allowlist_accepts_es256_proof_emits_accepted_metric(): void
    {
        // The post-LBDS-258 happy path: ES256 proof with the dual allowlist
        // emits an accepted line with alg='ES256'. jkt_prefix is the
        // first 8 chars of the JWK thumbprint so it can be correlated
        // with server-side traces.
        config()->set('jwt.dpop_allowed_algs', ['ES256', 'RS256']);

        $this->resetLogFile();

        $builder = DpopProofBuilder::generateEc();
        $refreshToken = $this->boundRefreshToken($builder);

        $this->refresh($refreshToken, $builder->proof())->assertOk();

        $entries = $this->readDpopMetricFromLog();
        $this->assertTrue($entries->contains(function (array $entry) {
            return ($entry['result'] ?? null) === 'accepted'
                && ($entry['alg'] ?? null) === 'ES256'
                && is_string($entry['jkt_prefix'] ?? null)
                && ($entry['htm'] ?? null) === 'POST';
        }), 'Expected at least one dpop_proof_verified metric with result=accepted, alg=ES256');
    }

    public function test_signature_failure_emits_metric_with_real_alg(): void
    {
        // Pre-merge review #3: a signature-failed proof must still report
        // the real alg in the metric (not 'unknown'). This is the path
        // that the runbook's cutover dashboard relies on for the
        // 30-day observation window.
        config()->set('jwt.dpop_allowed_algs', ['ES256', 'RS256']);

        $this->resetLogFile();

        $builder = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($builder);

        // Correct header JWK/thumbprint; signature segment no longer
        // verifies. The verifier must still surface alg=RS256 so the
        // cutover metric keeps working on this path.
        $proof = $builder->proof(['tamperSignature' => true]);

        $this->refresh($refreshToken, $proof)->assertUnauthorized();

        $entries = $this->readDpopMetricFromLog();
        $this->assertTrue($entries->contains(function (array $entry) {
            return ($entry['alg'] ?? null) === 'RS256'
                && ($entry['result'] ?? null) === 'rejected';
        }), 'Expected at least one dpop_proof_verified metric with alg=RS256, result=rejected');
    }

    public function test_thumbprint_mismatch_emits_metric_with_real_alg(): void
    {
        // Pre-merge review #3 sibling: a proof signed by one key but
        // claiming a different JWK thumbprint must report the real alg.
        config()->set('jwt.dpop_allowed_algs', ['ES256', 'RS256']);

        $this->resetLogFile();

        $bound = DpopProofBuilder::generate();
        $other = DpopProofBuilder::generate();
        $refreshToken = $this->boundRefreshToken($bound);

        $this->refresh($refreshToken, $other->proof())->assertUnauthorized();

        $entries = $this->readDpopMetricFromLog();
        $this->assertTrue($entries->contains(function (array $entry) {
            return ($entry['alg'] ?? null) === 'RS256'
                && ($entry['result'] ?? null) === 'rejected';
        }), 'Expected at least one dpop_proof_verified metric with alg=RS256, result=rejected');
    }

    /**
     * Wipe the daily log file so each metric test starts from a clean
     * slate. We assert against this file after the request, so any
     * leftover lines from earlier tests would muddy the assertions.
     *
     * Also pins `logging.default` to 'single' so the metric lines land
     * in a parseable file. Re-review finding B: the previous version
     * mutated `logging.default` and never restored it, polluting
     * subsequent tests that depended on the default channel.
     */
    private function resetLogFile(): void
    {
        config()->set('logging.default', 'single');
        $path = storage_path('logs/laravel.log');
        if (file_exists($path)) {
            file_put_contents($path, '');
        }
    }

    /**
     * Read every `dpop_proof_verified` entry written to today's log
     * file and decode its context array. The Monolog `single` channel
     * default LineFormatter emits:
     *   `[YYYY-MM-DD HH:MM:SS] channel.LEVEL: message {"k":"v",...}`
     * which is NOT one JSON object per line — only the trailing context
     * blob is JSON. We regex-strip the prefix and decode the suffix.
     */
    private function readDpopMetricFromLog(): \Illuminate\Support\Collection
    {
        $path = storage_path('logs/laravel.log');

        if (! file_exists($path)) {
            return collect();
        }

        $entries = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            // Monolog single-channel format ends with ` {json}` after
            // the message text. We look for the FIRST occurrence of
            // the message text followed by ` {` — using strrpos would
            // walk past the JSON content looking for a non-existent
            // trailing ` {`. (The line ends with `}`, not ` {`.)
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

    // --- Helpers (mirrors DpopRefreshTokenTest) ------------------------------------

    private function boundRefreshToken(DpopProofBuilder $builder): string
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $auth = app(OAuth2Service::class)->createAuthorizationCode($this->user, $this->client, [
            'redirect_uri' => 'https://app.example.com/auth/callback',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'test-app',
            'code' => $auth['code'],
            'redirect_uri' => 'https://app.example.com/auth/callback',
            'code_verifier' => $verifier,
            'dpop_public_key' => $builder->publicKey(),
        ]);

        $response->assertOk();

        return $response->json('refresh_token');
    }

    private function refresh(string $refreshToken, ?string $dpopHeader = null): TestResponse
    {
        $request = $this->withHeaders($dpopHeader === null ? [] : ['DPoP' => $dpopHeader]);

        return $request->postJson('/api/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => 'test-app',
            'refresh_token' => $refreshToken,
        ]);
    }
}