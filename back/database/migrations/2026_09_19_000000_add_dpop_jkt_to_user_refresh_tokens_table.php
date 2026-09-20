<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_refresh_tokens', function (Blueprint $table) {
            $table->string('dpop_jkt', 64)->nullable()->after('token_hash')->index();
        });
    }

    public function down(): void
    {
        Schema::table('user_refresh_tokens', function (Blueprint $table) {
            $table->dropIndex(['dpop_jkt']);
            $table->dropColumn('dpop_jkt');
        });
    }
};
