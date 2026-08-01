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
        Schema::table('user_sessions', function (Blueprint $table) {
            $table->dropColumn('token_hash');
        });

        Schema::table('user_sessions', function (Blueprint $table) {
            $table->text('plain_token')->nullable()->after('sso_client_id');
        });
    }
};
