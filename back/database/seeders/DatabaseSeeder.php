<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\AdminUserSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
        ]);

        // User::factory(10)->create();

        $testUser = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'user' => 'testuser',
                'password' => bcrypt('password'),
            ]
        );

        $viewerRole = Role::where('slug', 'viewer')->first();
        if ($viewerRole && !$testUser->hasRole('viewer')) {
            $testUser->roles()->attach($viewerRole->id, ['sso_client_id' => null]);
        }
    }
}
