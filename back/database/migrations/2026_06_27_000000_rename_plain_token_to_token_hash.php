<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            $table->dropColumn('plain_token');
        });

        Schema::table('user_sessions', function (Blueprint $table) {
            $table->char('token_hash', 64)->nullable()->after('sso_client_id')->index();
        });
    }

    public function down(): void
    {
        // SQLite refuses to drop a column that an index still covers ("error
        // in index user_sessions_token_hash_index after drop column"), while
        // MySQL drops that index implicitly along with the column. Dropping
        // the index explicitly keeps the rollback working on both drivers.
        if (Schema::hasIndex('user_sessions', 'user_sessions_token_hash_index')) {
            Schema::table('user_sessions', function (Blueprint $table) {
                $table->dropIndex('user_sessions_token_hash_index');
            });
        }

        Schema::table('user_sessions', function (Blueprint $table) {
            $table->dropColumn('token_hash');
        });

        Schema::table('user_sessions', function (Blueprint $table) {
            $table->text('plain_token')->nullable()->after('sso_client_id');
        });
    }
};
