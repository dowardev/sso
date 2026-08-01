<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key' => 'require_ssl',
                'value' => 'false',
                'type' => 'boolean',
                'description' => 'Rechazar clientes con redirect_url HTTP (sin SSL)',
            ],
            [
                'key' => 'ssl_exclusions',
                'value' => json_encode(['localhost', '127.0.0.1', '::1']),
                'type' => 'json',
                'description' => 'Hosts excluidos de la validación SSL',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::firstOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
