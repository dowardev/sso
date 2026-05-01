<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name' => 'Administrador',
                'slug' => 'admin',
                'description' => 'Usuario con acceso completo al sistema',
                'is_protected' => true,
            ],
            [
                'name' => 'Moderador',
                'slug' => 'moderator',
                'description' => 'Usuario con permisos de moderación',
                'is_protected' => true,
            ],
            [
                'name' => 'Estandar',
                'slug' => 'standard',
                'description' => 'Usuario estándar sin privilegios especiales',
                'is_protected' => true,
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['slug' => $role['slug']],
                $role
            );
        }
    }
}
