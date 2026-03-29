<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@sso.local'],
            [
                'name' => 'Administrator',
                'user' => 'admin',
                'password' => Hash::make('admin123'),
                'is_admin' => true,
            ]
        );

        $this->command->info('Admin user created: admin@sso.local / admin123');
    }
}
