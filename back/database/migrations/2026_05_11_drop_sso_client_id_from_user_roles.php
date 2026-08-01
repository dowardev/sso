<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Get actual column names from the table
        $columns = Schema::getColumnListing('user_roles');

        if (in_array('sso_client_id', $columns)) {
            // First drop foreign key if exists (but we know it doesn't exist based on earlier check)
            try {
                DB::statement('ALTER TABLE user_roles DROP COLUMN sso_client_id');
            } catch (Exception $e) {
                // Column might not exist or other error - continue
            }
        }

        // Drop the unique constraint that includes sso_client_id
        try {
            $indexes = DB::select("SHOW INDEX FROM user_roles WHERE Key_name LIKE '%user_roles%'");
            foreach ($indexes as $idx) {
                if (strpos($idx->Key_name, 'sso_client_id') !== false) {
                    DB::statement("ALTER TABLE user_roles DROP INDEX `{$idx->Key_name}`");
                }
            }
        } catch (Exception $e) {
            // Index might not exist
        }

        // Add unique constraint on just user_id + role_id
        try {
            DB::statement('ALTER TABLE user_roles ADD UNIQUE INDEX user_id_role_id_unique (user_id, role_id)');
        } catch (Exception $e) {
            // Might already exist or other error
        }
    }

    public function down(): void
    {
        // Remove the simplified unique index
        try {
            DB::statement('ALTER TABLE user_roles DROP INDEX user_id_role_id_unique');
        } catch (Exception $e) {
            // Might not exist
        }

        // Add column back
        DB::statement('ALTER TABLE user_roles ADD COLUMN sso_client_id BIGINT UNSIGNED NULL');

        // Add back original unique constraint
        try {
            DB::statement('ALTER TABLE user_roles ADD UNIQUE INDEX user_id_role_id_sso_client_id_unique (user_id, role_id, sso_client_id)');
        } catch (Exception $e) {
            // Ignore
        }
    }
};
