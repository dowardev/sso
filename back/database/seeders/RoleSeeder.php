<?php
// SPDX-License-Identifier: AGPL-3.0-only

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
                'description' => 'Acceso completo al sistema',
                'is_protected' => true,
            ],
            [
                'name' => 'Editor',
                'slug' => 'editor',
                'description' => 'Crear y editar contenido',
                'is_protected' => true,
            ],
            [
                'name' => 'Visor',
                'slug' => 'viewer',
                'description' => 'Solo lectura',
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
