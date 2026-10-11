<?php

// SPDX-License-Identifier: AGPL-3.0-only
//
// End-to-end feature tests for the TOTP enrollment flow: start → confirm →
// status → disable, plus the encrypted-at-rest invariant. The endpoint
// tests ride the same AuthController surface as the production code, so a
// regression in routing/middleware/validation surfaces here too.

namespace Tests\Feature;

use App\Models\TotpBackupCode;
use App\Models\TotpCredential;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class TotpEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_start_enrollment(): void
    {
        $this->postJson('/api/totp/enroll/start')
            ->assertStatus(401);

        $this->postJson('/api/totp/enroll/confirm', ['code' => '000000'])
            ->assertStatus(401);

        $this->deleteJson('/api/totp', ['password' => 'x'])
            ->assertStatus(401);
    }

    public function test_enroll_start_generates_secret_and_returns_otpauth_uri(): void
    {
        $user = $this->makeUser();

        $response = $this->withToken($this->loginAs($user))
            ->postJson('/api/totp/enroll/start');

        $response->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_uri', 'digits', 'period', 'algorithm'])
            ->assertJsonPath('digits', 6)
            ->assertJsonPath('period', 30)
            ->assertJsonPath('algorithm', 'SHA1');

        $secret = $response->json('secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+=*$/', $secret);

        // otpauth URI follows RFC 6238 §5.1: scheme, label, query with
        // secret + issuer. Authenticator apps key off these.
        $this->assertStringStartsWith('otpauth://totp/', $response->json('otpauth_uri'));
        $this->assertStringContainsString('secret='.$secret, $response->json('otpauth_uri'));
        $this->assertStringContainsString('digits=6', $response->json('otpauth_uri'));
        $this->assertStringContainsString('period=30', $response->json('otpauth_uri'));
    }

    public function test_enroll_start_persists_encrypted_secret_not_plaintext(): void
    {
        $user = $this->makeUser();
        $token = $this->loginAs($user);

        $response = $this->withToken($token)->postJson('/api/totp/enroll/start');
        $secret = $response->json('secret');

        $credential = TotpCredential::where('user_id', $user->id)->first();
        $this->assertNotNull($credential);

        // Encrypted-at-rest invariant: the DB column MUST NOT contain the
        // base32 secret in plaintext (a sub-string leak). Decrypting with
        // the same APP_KEY MUST return the secret — round-trip check.
        $this->assertStringNotContainsString($secret, $credential->encrypted_secret);
        $this->assertSame($secret, Crypt::decryptString($credential->encrypted_secret));
    }

    public function test_enroll_confirm_marks_credential_active_and_returns_backup_codes(): void
    {
        $user = $this->makeUser();
        $token = $this->loginAs($user);

        $start = $this->withToken($token)->postJson('/api/totp/enroll/start');
        $start->assertOk();
        $secret = $start->json('secret');
        $code = app(\App\Services\TotpService::class)->totpNow($secret);

        $confirm = $this->withToken($token)->postJson('/api/totp/enroll/confirm', ['code' => $code]);

        $confirm->assertOk()
            ->assertJsonStructure(['message', 'backup_codes', 'backup_codes_count'])
            ->assertJsonPath('backup_codes_count', TotpService::BACKUP_CODE_COUNT);

        $codes = $confirm->json('backup_codes');
        $this->assertCount(TotpService::BACKUP_CODE_COUNT, $codes);

        // Credential is now active: confirmed_at is set, last_used_step
        // has been advanced by the confirm pass.
        $credential = $user->fresh()->totpCredential;
        $this->assertNotNull($credential->confirmed_at);
        $this->assertNotNull($credential->last_used_step);

        // Backup code rows exist (one per plaintext), each hashed, none
        // used. Every hash matches its plaintext via Hash::check.
        $rows = TotpBackupCode::where('user_id', $user->id)->get();
        $this->assertCount(TotpService::BACKUP_CODE_COUNT, $rows);
        foreach ($codes as $code) {
            $matchFound = false;
            foreach ($rows as $row) {
                if (\Illuminate\Support\Facades\Hash::check($code, $row->code_hash)) {
                    $matchFound = true;
                    break;
                }
            }
            $this->assertTrue($matchFound, "Backup code '{$code}' has no matching hash");
        }
    }

    public function test_enroll_confirm_rejects_wrong_code(): void
    {
        $user = $this->makeUser();
        $token = $this->loginAs($user);
        $this->withToken($token)->postJson('/api/totp/enroll/start')->assertOk();

        $response = $this->withToken($token)->postJson('/api/totp/enroll/confirm', ['code' => '000000']);
        $response->assertStatus(422);

        // Confirm failure MUST NOT mark the credential active.
        $credential = $user->fresh()->totpCredential;
        $this->assertNull($credential->confirmed_at);
    }

    public function test_enroll_confirm_without_start_returns_400(): void
    {
        $user = $this->makeUser();
        $token = $this->loginAs($user);

        // No totp/enroll/start first.
        $response = $this->withToken($token)->postJson('/api/totp/enroll/confirm', ['code' => '123456']);
        $response->assertStatus(400);
    }

    public function test_enroll_start_re_enrolling_invalidates_old_backup_codes(): void
    {
        // Defense against accidental device-loss lockout by an attacker
        // who enrolls again: any leftover backup codes from the previous
        // enrollment MUST be wiped before the new secret is generated.
        $user = $this->makeUser();
        $token = $this->loginAs($user);

        $first = $this->withToken($token)->postJson('/api/totp/enroll/start');
        $first->assertOk();
        $firstSecret = $first->json('secret');
        $firstCode = app(\App\Services\TotpService::class)->totpNow($firstSecret);
        $this->withToken($token)->postJson('/api/totp/enroll/confirm', ['code' => $firstCode])->assertOk();

        $oldCodes = TotpBackupCode::where('user_id', $user->id)->get();
        $this->assertCount(TotpService::BACKUP_CODE_COUNT, $oldCodes);

        // Re-enroll: same user, fresh secret.
        $second = $this->withToken($token)->postJson('/api/totp/enroll/start');
        $second->assertOk();
        $this->assertNotSame($firstSecret, $second->json('secret'));

        $this->assertSame(0, TotpBackupCode::where('user_id', $user->id)->count());
    }

    public function test_status_reflects_enrollment_state(): void
    {
        $user = $this->makeUser();
        $token = $this->loginAs($user);

        // No enrollment yet.
        $this->withToken($token)
            ->getJson('/api/totp/status')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('backup_codes_remaining', 0);

        // Enroll + confirm.
        $start = $this->withToken($token)->postJson('/api/totp/enroll/start');
        $secret = $start->json('secret');
        $code = app(\App\Services\TotpService::class)->totpNow($secret);
        $this->withToken($token)->postJson('/api/totp/enroll/confirm', ['code' => $code])->assertOk();

        $status = $this->withToken($token)->getJson('/api/totp/status');
        $status->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('digits', 6)
            ->assertJsonPath('period', 30)
            ->assertJsonPath('algorithm', 'SHA1')
            ->assertJsonPath('backup_codes_remaining', TotpService::BACKUP_CODE_COUNT);
    }

    public function test_disable_requires_re_auth(): void
    {
        $user = $this->makeUser();
        $token = $this->loginAs($user);

        // No re-auth -> 422.
        $this->withToken($token)->deleteJson('/api/totp', [])
            ->assertStatus(422);

        // Wrong password -> 422.
        $this->withToken($token)->deleteJson('/api/totp', ['password' => 'wrong'])
            ->assertStatus(422);

        // Right password -> 200 + credential deleted.
        $this->withToken($token)->deleteJson('/api/totp', ['password' => 'correct-password'])
            ->assertOk();
        $this->assertNull($user->fresh()->totpCredential);
    }

    public function test_disable_deletes_backup_codes_too(): void
    {
        $user = $this->makeUser();
        $token = $this->loginAs($user);

        // Full enrollment.
        $start = $this->withToken($token)->postJson('/api/totp/enroll/start');
        $code = app(\App\Services\TotpService::class)->totpNow($start->json('secret'));
        $this->withToken($token)->postJson('/api/totp/enroll/confirm', ['code' => $code])->assertOk();

        $this->assertSame(TotpService::BACKUP_CODE_COUNT, TotpBackupCode::where('user_id', $user->id)->count());

        $this->withToken($token)->deleteJson('/api/totp', ['password' => 'correct-password'])->assertOk();

        $this->assertSame(0, TotpBackupCode::where('user_id', $user->id)->count());
    }

    public function test_backup_codes_regenerate_requires_re_auth_and_returns_new_set(): void
    {
        $user = $this->makeUser();
        $token = $this->loginAs($user);

        $start = $this->withToken($token)->postJson('/api/totp/enroll/start');
        $code = app(\App\Services\TotpService::class)->totpNow($start->json('secret'));
        $confirm = $this->withToken($token)->postJson('/api/totp/enroll/confirm', ['code' => $code]);
        $oldCodes = $confirm->json('backup_codes');

        // Wrong password.
        $this->withToken($token)->postJson('/api/totp/backup-codes/regenerate', ['password' => 'wrong'])
            ->assertStatus(422);

        $regen = $this->withToken($token)->postJson('/api/totp/backup-codes/regenerate', ['password' => 'correct-password']);
        $regen->assertOk()->assertJsonPath('backup_codes_count', TotpService::BACKUP_CODE_COUNT);

        // Old codes are gone (none match the new ones, and the count is
        // fresh). The new codes are a different set.
        $newCodes = $regen->json('backup_codes');
        $this->assertNotSame($oldCodes, $newCodes);
        $this->assertSame(TotpService::BACKUP_CODE_COUNT, TotpBackupCode::where('user_id', $user->id)->count());
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Totp Tester',
            'user' => 'totp_tester',
            'email' => 'totp_tester@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('correct-password'),
            'email_verified_at' => now(),
            'encryption_level' => 'basic',
        ], $overrides));
    }

    private function loginAs(User $user): string
    {
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk();

        return $response->json('token');
    }
}