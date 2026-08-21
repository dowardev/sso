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

class EmailLogoTest extends TestCase
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

    public function test_get_email_logo_returns_null_by_default(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/admin/settings/email-logo');

        $response->assertOk()
            ->assertJson(['email_logo_url' => null]);
    }

    public function test_get_email_logo_returns_configured_value(): void
    {
        Setting::updateOrCreate(
            ['key' => 'email_logo_url'],
            ['value' => '/storage/branding/email-logo.png', 'type' => 'string']
        );

        $response = $this->withToken($this->token)
            ->getJson('/api/admin/settings/email-logo');

        $response->assertOk()
            ->assertJson(['email_logo_url' => '/storage/branding/email-logo.png']);
    }

    public function test_admin_can_upload_email_logo(): void
    {
        $file = UploadedFile::fake()->create('email-logo.png', 200, 'image/png');

        $response = $this->withToken($this->token)
            ->post('/api/admin/settings/email-logo', ['image' => $file]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'email_logo_url']);

        Storage::disk('public')->assertExists('branding/email-logo.png');
        $this->assertSame(
            '/storage/branding/email-logo.png',
            Setting::get('email_logo_url')
        );
    }

    public function test_admin_upload_email_logo_rejects_non_image(): void
    {
        $file = UploadedFile::fake()->create('notes.txt', 100, 'text/plain');

        $response = $this->withToken($this->token)
            ->postJson('/api/admin/settings/email-logo', ['image' => $file]);

        $response->assertStatus(422);
        Storage::disk('public')->assertMissing('branding/email-logo.txt');
    }

    public function test_upload_email_logo_requires_admin(): void
    {
        $file = UploadedFile::fake()->create('email-logo.png', 200, 'image/png');

        $response = $this->post('/api/admin/settings/email-logo', ['image' => $file]);

        $response->assertStatus(401);
        Storage::disk('public')->assertMissing('branding/email-logo.png');
    }

    public function test_admin_can_remove_email_logo(): void
    {
        Storage::disk('public')->put('branding/email-logo.png', 'fake-content');
        Setting::updateOrCreate(
            ['key' => 'email_logo_url'],
            ['value' => '/storage/branding/email-logo.png', 'type' => 'string']
        );

        $response = $this->withToken($this->token)
            ->deleteJson('/api/admin/settings/email-logo');

        $response->assertOk()
            ->assertJson(['email_logo_url' => null]);

        Storage::disk('public')->assertMissing('branding/email-logo.png');
        $this->assertSame('', Setting::get('email_logo_url'));
    }

    public function test_remove_email_logo_does_not_touch_auth_logo(): void
    {
        Storage::disk('public')->put('branding/auth-logo.png', 'auth-content');
        Storage::disk('public')->put('branding/email-logo.png', 'email-content');
        Setting::updateOrCreate(
            ['key' => 'auth_logo_url'],
            ['value' => '/storage/branding/auth-logo.png', 'type' => 'string']
        );
        Setting::updateOrCreate(
            ['key' => 'email_logo_url'],
            ['value' => '/storage/branding/email-logo.png', 'type' => 'string']
        );

        $response = $this->withToken($this->token)
            ->deleteJson('/api/admin/settings/email-logo');

        $response->assertOk();
        Storage::disk('public')->assertExists('branding/auth-logo.png');
        Storage::disk('public')->assertMissing('branding/email-logo.png');
    }

    public function test_remove_email_logo_requires_admin(): void
    {
        $response = $this->deleteJson('/api/admin/settings/email-logo');

        $response->assertStatus(401);
    }
}