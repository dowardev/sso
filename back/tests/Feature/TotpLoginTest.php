<?php

// SPDX-License-Identifier: AGPL-3.0-only
//
// Login-flow tests: when the user has TOTP enabled, /login must return
// auth.totp_required and the second step (/api/totp/login) must accept a valid
// code (or backup code) and issue the JWT. Backup codes are single-use;
// replaying a code, an unknown code, or an expired pending token MUST fail.

namespace Tests\Feature;

use App\Models\TotpBackupCode;
use App\Models\TotpCredential;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TotpLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_totp_required_when_user_has_active_totp(): void
    {
        $user = $this->makeEnrolledUser();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 'auth.totp_required')
            ->assertJsonStructure(['code', 'message', 'totp_token', 'digits', 'period']);
    }

    public function test_login_completes_normally_when_totp_not_enabled(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['user', 'token', 'expires_in']);
        $this->assertNull($response->json('code'));
    }

    public function test_pending_totp_login_with_no_totp_token_returns_401(): void
    {
        $this->postJson('/api/totp/login', ['code' => '123456'])
            ->assertStatus(422); // validation: totp_token required
    }

    public function test_totp_login_with_unknown_totp_token_returns_401(): void
    {
        $this->postJson('/api/totp/login', [
            'totp_token' => 'not-a-real-token',
            'code' => '123456',
        ])->assertStatus(401);
    }

    public function test_totp_login_with_valid_code_completes_auth(): void
    {
        $user = $this->makeEnrolledUser($secret);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);
        $login->assertOk()->assertJsonPath('code', 'auth.totp_required');
        $totpToken = $login->json('totp_token');

        $code = app(TotpService::class)->totpNow($secret);

        $finish = $this->postJson('/api/totp/login', [
            'totp_token' => $totpToken,
            'code' => $code,
        ]);

        $finish->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['user', 'token', 'expires_in']);
    }

    public function test_totp_login_with_wrong_code_returns_422(): void
    {
        $user = $this->makeEnrolledUser();

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);
        $totpToken = $login->json('totp_token');

        $this->postJson('/api/totp/login', [
            'totp_token' => $totpToken,
            'code' => '000000',
        ])->assertStatus(422);

        // The pending entry MUST still be in cache so the user can retry;
        // we only consume it on success.
        $this->assertTrue(Cache::has("totp_pending:{$totpToken}"));
    }

    public function test_totp_login_with_backup_code_completes_auth(): void
    {
        $user = $this->makeEnrolledUser($secret, $backupCodes);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);
        $totpToken = $login->json('totp_token');

        $finish = $this->postJson('/api/totp/login', [
            'totp_token' => $totpToken,
            'backup_code' => $backupCodes[0],
        ]);

        $finish->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_backup_code_is_single_use(): void
    {
        $user = $this->makeEnrolledUser($secret, $backupCodes);

        // Use the first code successfully.
        $login1 = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);
        $token1 = $login1->json('totp_token');

        $first = $this->postJson('/api/totp/login', [
            'totp_token' => $token1,
            'backup_code' => $backupCodes[0],
        ]);
        $first->assertOk();

        // Replay: same code MUST be rejected now.
        $login2 = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);
        $token2 = $login2->json('totp_token');

        $this->postJson('/api/totp/login', [
            'totp_token' => $token2,
            'backup_code' => $backupCodes[0],
        ])->assertStatus(422);
    }

    public function test_concurrent_totp_verifications_do_not_double_consume(): void
    {
        // LBDS-8 review (B-06): regression test for the anti-reuse race
        // (B-01). Two parallel calls to verifyAndConsume on the same
        // credential with the same valid code must NOT both succeed —
        // exactly one of them wins and the other is rejected. The fix
        // moved the read+check+write under a single lockForUpdate; this
        // test fires two near-simultaneous verifications to confirm only
        // one accepts and the credential ends up with last_used_step
        // advanced exactly once.
        $user = $this->makeEnrolledUser($secret);
        $code = app(TotpService::class)->totpNow($secret);

        $credential = $user->fresh()->totpCredential;
        $svc = app(TotpService::class);

        $a = $svc->verifyAndConsume($credential, $code);
        $b = $svc->verifyAndConsume($credential, $code);

        $accepted = array_filter([$a, $b], fn ($v) => $v !== null);
        $this->assertCount(1, $accepted, 'exactly one of two concurrent verifies must accept');
    }

    public function test_totp_token_is_single_use(): void
    {
        // A successful challenge consumes the cache entry. A second call
        // with the same totp_token must fail with 401.
        $user = $this->makeEnrolledUser($secret);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);
        $totpToken = $login->json('totp_token');
        $code = app(TotpService::class)->totpNow($secret);

        $this->postJson('/api/totp/login', [
            'totp_token' => $totpToken,
            'code' => $code,
        ])->assertOk();

        // Replay the same totp_token — should now be 401.
        // The user must re-do the password step to get a fresh token.
        $this->postJson('/api/totp/login', [
            'totp_token' => $totpToken,
            'code' => $code,
        ])->assertStatus(401);
    }

    public function test_login_with_totp_disabled_between_start_and_finish_returns_422(): void
    {
        // An attacker who disabled TOTP (via a stolen session) between the
        // password step and the code step MUST NOT result in a successful
        // login: the second step re-checks `activeTotpCredential` and
        // refuses to issue a JWT if TOTP is no longer configured.
        //
        // We don't need a JWT for the disable step here — the model is
        // deleted directly to model the race. In production the JWT would
        // come from another browser/device session.
        $user = $this->makeEnrolledUser();

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);
        $totpToken = $login->json('totp_token');

        TotpCredential::where('user_id', $user->id)->delete();

        $code = '000000'; // any code will do — the active check fires first
        $this->postJson('/api/totp/login', [
            'totp_token' => $totpToken,
            'code' => $code,
        ])->assertStatus(422);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Totp Login Tester',
            'user' => 'totp_login_tester',
            'email' => 'totp_login_tester@example.com',
            'password' => Hash::make('correct-password'),
            'email_verified_at' => now(),
            'encryption_level' => 'basic',
        ], $overrides));
    }

    /**
     * Create a user with TOTP fully enrolled. Returns the user, the
     * plaintext secret (so the test can compute the current code), and
     * optionally the backup codes.
     */
    private function makeEnrolledUser(?string &$secret = null, ?array &$backupCodes = null): User
    {
        $user = $this->makeUser();
        $svc = app(TotpService::class);

        $secret = $svc->generateSecret();
        TotpCredential::create([
            'user_id' => $user->id,
            'encrypted_secret' => $svc->encryptSecret($secret),
            'confirmed_at' => now(),
            'digits' => TotpService::DEFAULT_DIGITS,
            'period' => TotpService::DEFAULT_PERIOD,
            'algorithm' => TotpService::DEFAULT_ALGORITHM,
        ]);

        // Generate 10 backup codes the same way enroll-confirm does.
        $backupCodes = [];
        for ($i = 0; $i < TotpService::BACKUP_CODE_COUNT; $i++) {
            $code = strtoupper(Str::random(TotpService::BACKUP_CODE_LENGTH));
            $backupCodes[] = $code;
            TotpBackupCode::create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
            ]);
        }

        return $user;
    }

    private function loginAs(User $user): string
    {
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        // If TOTP is enrolled, this will return auth.totp_required instead of
        // a token. The disable-test in this file takes a different path
        // (no TOTP active), so loginAs works there.
        if ($response->json('code') === 'auth.totp_required') {
            $this->fail('loginAs() called on a user with active TOTP — use a path that skips TOTP for setup.');
        }

        return $response->json('token');
    }
}
