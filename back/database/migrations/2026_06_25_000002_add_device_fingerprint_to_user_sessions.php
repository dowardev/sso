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
            $table->string('device_fingerprint', 64)->nullable()->after('dpop_public_key');
            $table->index(['user_id', 'remember_me']);
        });
    }

    public function down(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'remember_me']);
            $table->dropColumn('device_fingerprint');
        });
    }
};
