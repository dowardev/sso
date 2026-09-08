<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * NOTE: the first admin is intentionally NOT seeded — it is created by the
     * frontend setup wizard at `/setup` on first access (the wizard is blocked
     * only once any user exists, via EnsureSetupNotCompleted). Keeping the
     * users table empty here lets `migrate:fresh --seed` start from zero and
     * the operator create the admin through the wizard.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            SettingSeeder::class,
        ]);
    }
}
