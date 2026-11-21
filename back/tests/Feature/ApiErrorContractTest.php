<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\SsoClient;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiErrorContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @test */
    public function validation_failure_on_register_with_unknown_client_id_returns_error_details_with_client_not_registered_code(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'user' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'StrongP@ss1x',
            'password_confirmation' => 'StrongP@ss1x',
            'client_id' => 'unknown-client',
        ]);

        $response->assertStatus(422);

        // errors map (backwards compatibility) stays intact
        $this->assertArrayHasKey('errors', $response->json());
        $this->assertArrayHasKey('client_id', $response->json('errors'));
        $this->assertIsArray($response->json('errors.client_id'));

        // NEW: error_details with code, field, message
        $this->assertArrayHasKey('error_details', $response->json());
        $this->assertArrayHasKey('client_id', $response->json('error_details'));
        $this->assertIsArray($response->json('error_details.client_id'));

        $detail = $response->json('error_details.client_id')[0];
        $this->assertEquals('client.not_registered', $detail['code']);
        $this->assertEquals('client_id', $detail['field']);
        $this->assertIsString($detail['message']);
        $this->assertArrayNotHasKey('params', $detail); // exists rule has no min/max/date params
    }

    /** @test */
    public function validation_failure_on_register_with_missing_required_field_returns_validation_required_code(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'user' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'StrongP@ss1x',
            'password_confirmation' => 'StrongP@ss1x',
            // client_id missing
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('error_details', $response->json());
        $this->assertArrayHasKey('client_id', $response->json('error_details'));

        $detail = $response->json('error_details.client_id')[0];
        $this->assertEquals('validation.required', $detail['code']);
        $this->assertEquals('client_id', $detail['field']);
        $this->assertIsString($detail['message']);
    }

    /** @test */
    public function validation_failure_on_password_regex_returns_validation_password_complex_code(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'user' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'abcdefghijklmnop', // long enough, but no uppercase/number/special
            'password_confirmation' => 'abcdefghijklmnop',
            'client_id' => 'unknown-client', // also fails exists but we check password
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('error_details', $response->json());
        $this->assertArrayHasKey('password', $response->json('error_details'));

        $detail = $response->json('error_details.password')[0];
        $this->assertEquals('validation.password_complex', $detail['code']);
        $this->assertEquals('password', $detail['field']);
        $this->assertIsString($detail['message']);
    }

    /** @test */
    public function validation_failure_on_min_rule_includes_params_in_error_details(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'user' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'Ab1!', // too short (min:12)
            'password_confirmation' => 'Ab1!',
            'client_id' => 'unknown-client',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('error_details', $response->json());

        $passwordDetail = $response->json('error_details.password')[0];
        $this->assertEquals('validation.min', $passwordDetail['code']);
        $this->assertEquals('password', $passwordDetail['field']);
        $this->assertArrayHasKey('params', $passwordDetail);
        $this->assertEquals(12, $passwordDetail['params']['min']);
    }

    /** @test */
    public function auth_failure_wrong_credentials_returns_auth_invalid_credentials_code(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('StrongP@ss1x'),
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'WrongP@ss1',
        ]);

        $response->assertStatus(401);
        $json = $response->json();
        $this->assertArrayHasKey('code', $json);
        $this->assertEquals('auth.invalid_credentials', $json['code']);
        $this->assertArrayHasKey('message', $json);
    }

    /** @test */
    public function unauthenticated_request_to_protected_endpoint_returns_auth_token_missing_code(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
        $json = $response->json();
        $this->assertArrayHasKey('code', $json);
        $this->assertEquals('auth.token_missing', $json['code']);
        $this->assertArrayHasKey('message', $json);
    }

    /** @test */
    public function oauth_token_endpoint_with_unknown_client_id_keeps_the_existing_contract_and_adds_the_code(): void
    {
        // Request token with an unknown client: the endpoint keeps its existing
        // validation contract (422 + errors map) and only adds the code.
        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => 'unknown-client',
            'client_secret' => 'secret',
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertIsArray($json['errors']['client_id']);
        $this->assertArrayHasKey('error_details', $json);
        $this->assertEquals('client.not_registered', $json['error_details']['client_id'][0]['code']);
        $this->assertEquals('client_id', $json['error_details']['client_id'][0]['field']);
    }

    /** @test */
    public function oauth_token_endpoint_with_disabled_client_returns_code_without_changing_the_shape(): void
    {
        SsoClient::create([
            'name' => 'Disabled App',
            'client_id' => 'disabled-app',
            'redirect_url' => 'https://app.example.com/auth/callback',
            'allowed_origins' => ['https://app.example.com'],
            'grant_types' => ['client_credentials'],
            'active' => false,
        ]);

        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => 'disabled-app',
            'client_secret' => 'secret',
        ]);

        $response->assertStatus(403);
        $json = $response->json();

        // The existing `message` key stays; `code` is additive.
        $this->assertArrayHasKey('message', $json);
        $this->assertArrayHasKey('code', $json);
        $this->assertEquals('client.not_registered', $json['code']);
    }

    /** @test */
    public function throttled_login_returns_auth_too_many_attempts_code(): void
    {
        // Make 4 failed login attempts to trigger lockout
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', [
                'email' => 'nonexistent@example.com',
                'password' => 'wrongpassword',
            ]);
        }

        // 5th attempt should be throttled
        $response = $this->postJson('/api/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(429);
        $json = $response->json();
        $this->assertArrayHasKey('code', $json);
        $this->assertEquals('auth.too_many_attempts', $json['code']);
        $this->assertArrayHasKey('message', $json);
    }

    /** @test */
    public function rejected_input_leaves_stored_data_unchanged(): void
    {
        // Ensure no user is created from failed validation
        $this->postJson('/api/register', [
            'name' => 'Test User',
            'user' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'StrongP@ss1x',
            'password_confirmation' => 'StrongP@ss1x',
            'client_id' => 'unknown-client',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);

        // Ensure login attempts counter is not polluted by validation errors
        $initialCount = \DB::table('login_attempts')->count();

        $this->postJson('/api/register', [
            'name' => 'Test User',
            'user' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'StrongP@ss1x',
            'password_confirmation' => 'StrongP@ss1x',
            'client_id' => 'unknown-client',
        ]);

        // Validation errors should not create login_attempts records
        $this->assertEquals($initialCount, \DB::table('login_attempts')->count());
    }
}
