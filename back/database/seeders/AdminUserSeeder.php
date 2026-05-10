<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'admin@sso.local'],
            [
                'name' => 'Administrator',
                'user' => 'admin',
                'password' => Hash::make('admin123'),
            ]
        );

        $adminRole = Role::where('slug', 'admin')->first();
        if ($adminRole && !$user->hasRole('admin')) {
            $user->roles()->attach($adminRole->id, ['sso_client_id' => null]);
        }

        $this->command->info('Admin user created: admin@sso.local / admin123');
    }
}
