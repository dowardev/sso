<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TotpCredential;
use App\Models\User;
use App\Services\TotpService;
use App\Support\ApiError;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backend regression test for single-language API (English) with stable codes.
 *
 * Covers a representative sweep of endpoints to assert that:
 * - Every touched endpoint answers with a `code` and English prose
 * - Success responses get `success.*` codes
 * - Error responses get mapped error codes
 * - Strings inside `errors` are English (no Spanish characters)
 * - The backend code catalogue exposes every code listed in the contract
 */
class ApiEnglishMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @test */
    public function success_responses_carry_success_codes_and_english_prose(): void
    {
        // 1. Create an admin user to authenticate as
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('StrongP@ss1x'),
            'email_verified_at' => now(),
        ]);
        $adminRole = Role::where('slug', 'admin')->first();
        $admin->roles()->attach($adminRole->id);

        $token = $this->loginAs($admin);

        // 2. Role create -> success.role_created
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/admin/roles', [
                'name' => 'Test Role',
                'slug' => 'test-role',
                'description' => 'A test role',
            ]);
        $response->assertStatus(201);
        $json = $response->json();
        $this->assertArrayHasKey('code', $json);
        $this->assertEquals('success.role_created', $json['code']);
        $this->assertEquals('Role created successfully.', $json['message']);
        $this->assertStringNotContainsString('ó', $json['message']);
        $this->assertStringNotContainsString('á', $json['message']);
        $this->assertStringNotContainsString('é', $json['message']);
        $this->assertStringNotContainsString('í', $json['message']);
        $this->assertStringNotContainsString('ú', $json['message']);
        $this->assertStringNotContainsString('ñ', $json['message']);

        // 3. SSO client create -> success.client_created
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/admin/clients', [
                'name' => 'Test Client',
                'client_id' => 'test-client',
                'redirect_url' => 'https://app.example.com/auth/callback',
                'allowed_origins' => ['https://app.example.com'],
                'grant_types' => ['authorization_code'],
            ]);
        $response->assertStatus(201);
        $json = $response->json();
        $this->assertArrayHasKey('code', $json);
        $this->assertEquals('success.client_created', $json['code']);
        $this->assertEquals('SSO client created successfully.', $json['message']);
        $this->assertStringNotContainsString('ó', $json['message']);
    }

    /** @test */
    public function error_responses_that_previously_had_no_code_now_have_one(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('StrongP@ss1x'),
            'email_verified_at' => now(),
        ]);
        $adminRole = Role::where('slug', 'admin')->first();
        $admin->roles()->attach($adminRole->id);

        $token = $this->loginAs($admin);

        // 1. Protected role update -> role.protected_update (403)
        $protectedRole = Role::where('slug', 'admin')->first();
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson("/api/admin/roles/{$protectedRole->id}", [
                'name' => 'Modified Admin',
                'slug' => 'admin',
                'description' => 'Modified',
            ]);
        $response->assertStatus(422);
        $json = $response->json();
        $this->assertArrayHasKey('code', $json);
        $this->assertEquals('role.protected_update', $json['code']);
        $this->assertEquals('You cannot modify a protected role.', $json['message']);
        $this->assertStringNotContainsString('ó', $json['message']);

        // 2. Image required (setting upload without image) -> setting.image_required (422)
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/admin/settings/auth-logo', []);
        $response->assertStatus(422);
        $json = $response->json();
        $this->assertArrayHasKey('error_details', $json);
        $detail = $json['error_details']['image'][0];
        $this->assertEquals('setting.image_required', $detail['code']);
        $this->assertEquals('image', $detail['field']);
        $this->assertStringNotContainsString('ó', $detail['message']);
    }

    /** @test */
    public function login_validation_failure_returns_english_errors_and_error_details(): void
    {
        // Missing email/user field
        $response = $this->postJson('/api/login', [
            'password' => 'StrongP@ss1x',
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        // Top-level message is English
        $this->assertArrayHasKey('message', $json);
        $this->assertEquals('Validation error.', $json['message']);
        $this->assertStringNotContainsString('ó', $json['message']);

        // errors map strings are English
        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('email', $json['errors']);
        foreach ($json['errors']['email'] as $err) {
            $this->assertStringNotContainsString('ó', $err);
            $this->assertStringNotContainsString('á', $err);
            $this->assertStringNotContainsString('é', $err);
            $this->assertStringNotContainsString('í', $err);
            $this->assertStringNotContainsString('ú', $err);
            $this->assertStringNotContainsString('ñ', $err);
        }

        // error_details present with code
        $this->assertArrayHasKey('error_details', $json);
        $this->assertArrayHasKey('email', $json['error_details']);
        $detail = $json['error_details']['email'][0];
        $this->assertEquals('validation.required', $detail['code']);
        $this->assertEquals('email', $detail['field']);
        $this->assertStringNotContainsString('ó', $detail['message']);
    }

    /** @test */
    public function auth_totp_required_challenge_returns_correct_code_and_english(): void
    {
        // Create user with TOTP enabled
        $user = User::factory()->create([
            'email' => 'totpuser@example.com',
            'password' => bcrypt('StrongP@ss1x'),
            'email_verified_at' => now(),
        ]);

        // Create a confirmed TOTP credential
        TotpCredential::create([
            'user_id' => $user->id,
            'encrypted_secret' => app(TotpService::class)->encryptSecret('JBSWY3DPEHPK3PXP'),
            'confirmed_at' => now(),
            'digits' => 6,
            'period' => 30,
            'algorithm' => 'SHA1',
        ]);

        // Attempt login - should return TOTP challenge
        $response = $this->postJson('/api/login', [
            'email' => 'totpuser@example.com',
            'password' => 'StrongP@ss1x',
        ]);

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertArrayHasKey('code', $json);
        $this->assertEquals('auth.totp_required', $json['code']);
        $this->assertEquals('You need your TOTP code (or a backup code) to continue.', $json['message']);
        $this->assertStringNotContainsString('ó', $json['message']);
        $this->assertStringNotContainsString('á', $json['message']);
        $this->assertArrayHasKey('totp_token', $json);
        $this->assertArrayHasKey('digits', $json);
        $this->assertArrayHasKey('period', $json);
    }

    /** @test */
    public function throttle_response_returns_auth_too_many_attempts_code_and_english(): void
    {
        // Make 4 failed login attempts to trigger lockout
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', [
                'email' => 'throttle@example.com',
                'password' => 'wrongpassword',
            ]);
        }

        // 5th attempt should be throttled
        $response = $this->postJson('/api/login', [
            'email' => 'throttle@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(429);
        $json = $response->json();

        $this->assertArrayHasKey('code', $json);
        $this->assertEquals('auth.too_many_attempts', $json['code']);
        $this->assertEquals('Too many attempts. The account is temporarily locked.', $json['message']);
        $this->assertStringNotContainsString('ó', $json['message']);
        $this->assertStringNotContainsString('á', $json['message']);
    }

    /** @test */
    public function backend_code_catalogue_exposes_all_contract_codes(): void
    {
        $contractErrorCodes = [
            // New error codes from contract
            'csp.hash_already_allowed',
            'email_verification.invalid_link',
            'role.protected_update',
            'role.protected_delete',
            'role.in_use',
            'setting.image_required',
            'setup.already_completed',
            'user.self_delete_forbidden',
            'session.self_revoke_forbidden',
            'profile.encrypted_profile_required',
            'auth.totp_required',
            'auth.passkey_required',
            'auth.passkey_session_expired',
            'auth.passkey_session_used',
            'auth.totp_not_enabled',
            'auth.advanced_encryption_already_set',
        ];

        $contractSuccessCodes = [
            'success.csp_hash_added',
            'success.email_verified',
            'success.verification_email_sent',
            'success.verification_email_resent',
            'success.role_created',
            'success.role_updated',
            'success.role_deleted',
            'success.setting_updated',
            'success.logo_updated',
            'success.logo_deleted',
            'success.email_logo_updated',
            'success.email_logo_deleted',
            'success.admin_created',
            'success.client_created',
            'success.client_updated',
            'success.client_deleted',
            'success.user_updated',
            'success.user_deleted',
            'success.user_roles_updated',
            'success.session_revoked',
            'success.user_sessions_revoked',
            'success.sessions_revoked',
            'success.account_recovered',
            'success.password_updated',
            'success.password_updated_sessions_revoked',
            'success.upgrade_to_advanced',
            'success.logout',
            'success.profile_updated',
            'success.passkey_registered',
            'success.passkey_deleted',
            'success.totp_enabled',
            'success.totp_disabled',
            'success.backup_codes_regenerated',
            'success.account_deleted',
            'success.authorization_denied',
            'success.access_revoked',
        ];

        // Check all error codes exist as constants in ApiError
        foreach ($contractErrorCodes as $code) {
            $constantName = strtoupper(str_replace(['.', '-'], '_', $code));
            $this->assertTrue(
                defined("App\\Support\\ApiError::{$constantName}"),
                "Missing ApiError constant for code: {$code} (expected constant: {$constantName})"
            );
            $this->assertEquals($code, constant("App\\Support\\ApiError::{$constantName}"));
        }

        // Check all success codes exist as constants in ApiError
        foreach ($contractSuccessCodes as $code) {
            $constantName = strtoupper(str_replace(['.', '-'], '_', $code));
            $this->assertTrue(
                defined("App\\Support\\ApiError::{$constantName}"),
                "Missing ApiError constant for success code: {$code} (expected constant: {$constantName})"
            );
            $this->assertEquals($code, constant("App\\Support\\ApiError::{$constantName}"));
        }

        // Also verify legacy codes are NOT present as constants (they should be replaced)
        $this->assertFalse(defined('App\\Support\\ApiError::TOTP_REQUIRED'), 'Legacy TOTP_REQUIRED constant should not exist');
        $this->assertFalse(defined('App\\Support\\ApiError::PASSKEY_REQUIRED'), 'Legacy PASSKEY_REQUIRED constant should not exist');
    }

    /** @test */
    public function api_error_default_messages_are_english(): void
    {
        // Test that defaultMessageForCode returns English for new codes
        $reflection = new \ReflectionClass(ApiError::class);
        $method = $reflection->getMethod('defaultMessageForCode');
        $method->setAccessible(true);

        $testCases = [
            'validation.required' => 'This field is required.',
            'validation.string' => 'This field must be text.',
            'validation.min' => 'This field must have at least {min} characters.',
            'validation.max' => 'This field cannot exceed {max} characters.',
            'validation.email' => 'This field must be a valid email.',
            'validation.unique' => 'This field is already in use.',
            'validation.exists' => 'The value of this field does not exist.',
            'validation.confirmed' => 'The confirmation of this field does not match.',
            'validation.regex' => 'This field does not have a valid format.',
            'validation.json' => 'This field must be valid JSON.',
            'validation.boolean' => 'This field must be true or false.',
            'validation.in' => 'The value of this field is not valid.',
            'validation.array' => 'This field must be a list.',
            'validation.integer' => 'This field must be an integer.',
            'validation.numeric' => 'This field must be a number.',
            'validation.url' => 'This field must be a valid URL.',
            'validation.date' => 'This field must be a valid date.',
            'validation.after_or_equal' => 'This field must be a date after or equal to {date}.',
            'validation.image' => 'This field must be a valid image.',
            'validation.password_complex' => 'The password must have at least 8 characters, an uppercase letter, a number, and a special character.',
            'validation.unknown' => 'Validation error. Check the data and try again.',
            'client.not_registered' => 'The application is not registered in the SSO.',
        ];

        foreach ($testCases as $code => $expectedMessage) {
            $message = $method->invoke(null, $code);
            $this->assertEquals($expectedMessage, $message, "Code {$code} should have English default message");
            $this->assertStringNotContainsString('ó', $message);
            $this->assertStringNotContainsString('á', $message);
            $this->assertStringNotContainsString('é', $message);
            $this->assertStringNotContainsString('í', $message);
            $this->assertStringNotContainsString('ú', $message);
            $this->assertStringNotContainsString('ñ', $message);
        }
    }

    /** @test */
    public function auth_service_exceptions_are_english_and_mapped_to_codes(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('StrongP@ss1x'),
            'email_verified_at' => now(),
        ]);
        $adminRole = Role::where('slug', 'admin')->first();
        $admin->roles()->attach($adminRole->id);

        $token = $this->loginAs($admin);

        // Test user.self_delete_forbidden (trying to delete own user)
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->deleteJson("/api/admin/users/{$admin->id}");
        $response->assertStatus(422);
        $json = $response->json();
        $this->assertEquals('user.self_delete_forbidden', $json['code']);
        $this->assertEquals('You cannot delete your own user.', $json['message']);
        $this->assertStringNotContainsString('ó', $json['message']);

        // Test session.self_revoke_forbidden (trying to revoke own sessions from admin)
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->deleteJson("/api/admin/users/{$admin->id}/sessions");
        $response->assertStatus(422);
        $json = $response->json();
        $this->assertEquals('session.self_revoke_forbidden', $json['code']);
        $this->assertEquals('You cannot revoke your own sessions from the admin panel.', $json['message']);
        $this->assertStringNotContainsString('ó', $json['message']);
    }

    private function loginAs(User $user): string
    {
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'StrongP@ss1x',
        ]);

        return $response->json('token');
    }
}
