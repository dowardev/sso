<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupAdminEmailVerifiedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_first_admin_is_created_verified(): void
    {
        $this->postJson('/api/setup/admin', [
            'name' => 'Admin User',
            'user' => 'admin_user',
            'email' => 'admin@example.com',
            'password' => 'StrongP@ss1',
            'password_confirmation' => 'StrongP@ss1',
        ])->assertStatus(201);

        $admin = User::where('email', 'admin@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertNotNull($admin->email_verified_at);
    }
}