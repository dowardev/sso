<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // LBDS-8 review (B-08): the hot query in TotpService::verifyBackupCode
        // is `where user_id = ? and used_at is null`. The original index on
        // `user_id` alone forces the engine to read every row for the user
        // and filter used_at in memory. With a composite (user_id, used_at)
        // index the same query becomes an index range scan that only touches
        // the unused rows.
        Schema::table('totp_backup_codes', function (Blueprint $table) {
            $table->index(['user_id', 'used_at'], 'totp_backup_codes_user_id_used_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('totp_backup_codes', function (Blueprint $table) {
            $table->dropIndex('totp_backup_codes_user_id_used_at_index');
        });
    }
};