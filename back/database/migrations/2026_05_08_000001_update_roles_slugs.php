<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')
            ->where('slug', 'standard')
            ->update(['slug' => 'viewer', 'name' => 'Visor', 'description' => 'Solo lectura']);

        DB::table('roles')
            ->where('slug', 'moderator')
            ->delete();

        DB::table('roles')->updateOrInsert(
            ['slug' => 'editor'],
            [
                'name' => 'Editor',
                'description' => 'Crear y editar contenido',
                'is_protected' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('slug', 'editor')
            ->delete();

        DB::table('roles')
            ->where('slug', 'viewer')
            ->update(['slug' => 'standard', 'name' => 'Estandar', 'description' => '']);

        DB::table('roles')->insert([
            'name' => 'Moderador',
            'slug' => 'moderator',
            'description' => '',
            'is_protected' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};