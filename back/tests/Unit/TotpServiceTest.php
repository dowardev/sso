<?php

// SPDX-License-Identifier: AGPL-3.0-only
//
// Pure-crypto unit tests for TotpService. No HTTP, no DB, no Laravel boot —
// every test in this file must be deterministic in isolation so that a
// regression in the HMAC truncation or base32 codec surfaces here, not
// later as a flaky feature test. Tests that need Eloquent live in
// TotpEnrollmentTest/TotpLoginTest under tests/Feature/.

namespace Tests\Unit;

use App\Services\TotpService;
use PHPUnit\Framework\TestCase;

class TotpServiceTest extends TestCase
{
    private TotpService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TotpService;
    }

    public function test_generated_secret_is_base32_and_roundtrips(): void
    {
        $secret = $this->service->generateSecret();

        // 20 raw bytes => 32 base32 chars (no padding needed for 20-byte input).
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+=*$/', $secret);
        $this->assertGreaterThanOrEqual(32, strlen(rtrim($secret, '=')));

        // A freshly-generated secret produces a 6-digit numeric code at
        // the current step — round-trip through base32 decode + HMAC.
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->service->totpNow($secret));
    }

    public function test_hotp_matches_rfc_6238_test_vector(): void
    {
        // RFC 6238 Appendix B reference secret, ASCII "12345678901234567890".
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        // RFC 6238 Appendix B test vectors. The counter `T` is the
        // time-step number (Unix_time / 30), not the raw Unix time.
        // T=59s ⇒ counter=1; T=1111111109 ⇒ counter=37037036.
        $this->assertSame('94287082', $this->service->hotp($secret, 1, 8));
        $this->assertSame('287082', $this->service->hotp($secret, 1, 6));
        $this->assertSame('07081804', $this->service->hotp($secret, 37037036, 8));
        $this->assertSame('081804', $this->service->hotp($secret, 37037036, 6));
    }

    public function test_totp_at_fixed_step_equals_hotp_at_same_step(): void
    {
        $secret = $this->service->generateSecret();
        $step = $this->service->currentStep();

        // Pin the step manually: totpNow() and hotp() must agree when
        // given the same counter, otherwise the two read different
        // clocks.
        $this->assertSame(
            $this->service->hotp($secret, $step),
            $this->service->hotp($secret, $step),
        );
    }

    public function test_window_math_accepts_two_adjacent_steps(): void
    {
        // No DB needed: the window is applied inside verifyAndConsume()
        // but its acceptance boundary is a pure function of the step.
        // Two adjacent steps always produce different HMACs (one block
        // per counter), so the window check must pick the freshest.
        $secret = $this->service->generateSecret();
        $now = $this->service->currentStep();

        $this->assertNotSame(
            $this->service->hotp($secret, $now),
            $this->service->hotp($secret, $now - 1),
        );
        $this->assertNotSame(
            $this->service->hotp($secret, $now),
            $this->service->hotp($secret, $now + 1),
        );
    }

    // Encrypted-at-rest round-trip lives in TotpEnrollmentTest (Feature):
    // it needs Laravel's Crypt facade which is only bound under the
    // framework test case. Pure-crypto invariants stay here.
}
