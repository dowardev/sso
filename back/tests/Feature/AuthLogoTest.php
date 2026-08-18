<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthLogoTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password123'),
        ]);
        $adminRole = Role::where('slug', 'admin')->first();
        $this->admin->roles()->attach($adminRole->id, ['sso_client_id' => null]);

        $response = $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'password123',
        ]);
        $this->token = $response->json('token');
    }

    public function test_public_settings_returns_null_logo_by_default(): void
    {
        $response = $this->getJson('/api/settings/public');

        $response->assertOk()
            ->assertJson(['auth_logo_url' => null]);
    }

    public function test_public_settings_returns_configured_logo(): void
    {
        Setting::updateOrCreate(
            ['key' => 'auth_logo_url'],
            ['value' => '/storage/branding/auth-logo.png', 'type' => 'string']
        );

        $response = $this->getJson('/api/settings/public');

        $response->assertOk()
            ->assertJson(['auth_logo_url' => '/storage/branding/auth-logo.png']);
    }

    public function test_admin_can_upload_logo(): void
    {
        $file = UploadedFile::fake()->create('logo.png', 200, 'image/png');

        $response = $this->withToken($this->token)
            ->post('/api/admin/settings/auth-logo', ['image' => $file]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'auth_logo_url']);

        Storage::disk('public')->assertExists('branding/auth-logo.png');
        $this->assertSame(
            '/storage/branding/auth-logo.png',
            Setting::get('auth_logo_url')
        );
    }

    public function test_admin_upload_rejects_non_image(): void
    {
        $file = UploadedFile::fake()->create('notes.txt', 100, 'text/plain');

        $response = $this->withToken($this->token)
            ->postJson('/api/admin/settings/auth-logo', ['image' => $file]);

        $response->assertStatus(422);
        Storage::disk('public')->assertMissing('branding/auth-logo.txt');
    }

    public function test_upload_requires_admin(): void
    {
        $file = UploadedFile::fake()->create('logo.png', 200, 'image/png');

        $response = $this->post('/api/admin/settings/auth-logo', ['image' => $file]);

        $response->assertStatus(401);
        Storage::disk('public')->assertMissing('branding/auth-logo.png');
    }

    public function test_admin_can_remove_logo(): void
    {
        Storage::disk('public')->put('branding/auth-logo.png', 'fake-content');
        Setting::updateOrCreate(
            ['key' => 'auth_logo_url'],
            ['value' => '/storage/branding/auth-logo.png', 'type' => 'string']
        );

        $response = $this->withToken($this->token)
            ->deleteJson('/api/admin/settings/auth-logo');

        $response->assertOk()
            ->assertJson(['auth_logo_url' => null]);

        Storage::disk('public')->assertMissing('branding/auth-logo.png');
        $this->assertSame('', Setting::get('auth_logo_url'));
    }

    public function test_admin_can_remove_logo_uploaded_with_uppercase_extension(): void
    {
        $file = UploadedFile::fake()->create('logo.PNG', 200, 'image/png');

        $response = $this->withToken($this->token)
            ->post('/api/admin/settings/auth-logo', ['image' => $file]);

        $response->assertOk();

        Storage::disk('public')->assertExists('branding/auth-logo.png');

        $response = $this->withToken($this->token)
            ->deleteJson('/api/admin/settings/auth-logo');

        $response->assertOk()
            ->assertJson(['auth_logo_url' => null]);

        Storage::disk('public')->assertMissing('branding/auth-logo.png');
        $this->assertSame('', Setting::get('auth_logo_url'));
    }

    public function test_remove_logo_requires_admin(): void
    {
        $response = $this->deleteJson('/api/admin/settings/auth-logo');

        $response->assertStatus(401);
    }
}
