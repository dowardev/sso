<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupAdminRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Admin User',
            'user' => 'admin_user',
            'email' => 'admin@example.com',
            'password' => 'StrongAdminP@ss1',
            'password_confirmation' => 'StrongAdminP@ss1',
        ], $overrides);
    }

    public function test_creates_admin_successfully(): void
    {
        $response = $this->postJson('/api/setup/admin', $this->validPayload());

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Administrator created successfully.',
                'user' => ['email' => 'admin@example.com'],
            ]);

        $user = User::where('email', 'admin@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_password_min_length_is_ten(): void
    {
        $response = $this->postJson('/api/setup/admin', $this->validPayload([
            'password' => 'Abcde123!',
            'password_confirmation' => 'Abcde123!',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_password_requires_uppercase(): void
    {
        $response = $this->postJson('/api/setup/admin', $this->validPayload([
            'password' => 'abcdef123!xy',
            'password_confirmation' => 'abcdef123!xy',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
        $this->assertStringContainsString('uppercase letter', $response->json('errors.password')[0]);
    }

    public function test_password_requires_lowercase(): void
    {
        $response = $this->postJson('/api/setup/admin', $this->validPayload([
            'password' => 'ABCDEF123!XY',
            'password_confirmation' => 'ABCDEF123!XY',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
        $this->assertStringContainsString('lowercase letter', $response->json('errors.password')[0]);
    }

    public function test_password_requires_number(): void
    {
        $response = $this->postJson('/api/setup/admin', $this->validPayload([
            'password' => 'Abcdefghij!x',
            'password_confirmation' => 'Abcdefghij!x',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
        $this->assertStringContainsString('number', $response->json('errors.password')[0]);
    }

    public function test_password_requires_symbol(): void
    {
        $response = $this->postJson('/api/setup/admin', $this->validPayload([
            'password' => 'Abcdefghij1x',
            'password_confirmation' => 'Abcdefghij1x',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
        $this->assertStringContainsString('special character', $response->json('errors.password')[0]);
    }

    public function test_password_confirmation_must_match(): void
    {
        $response = $this->postJson('/api/setup/admin', $this->validPayload([
            'password_confirmation' => 'DifferentP@ss1',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_returns_all_password_errors_at_once(): void
    {
        $response = $this->postJson('/api/setup/admin', $this->validPayload([
            'password' => 'abcdefghij',
            'password_confirmation' => 'abcdefghij',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
        $this->assertGreaterThanOrEqual(2, count($response->json('errors.password')));
    }

    public function test_returns_403_when_users_already_exist(): void
    {
        User::factory()->create();

        $response = $this->postJson('/api/setup/admin', $this->validPayload());

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Ya existen usuarios registrados en el sistema.']);
    }
}
