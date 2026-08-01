<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sso_clients', function (Blueprint $table) {
            $table->json('grant_types')->nullable()->after('allowed_origins');
        });

        DB::table('sso_clients')->update([
            'grant_types' => json_encode(['authorization_code']),
        ]);
    }

    public function down(): void
    {
        Schema::table('sso_clients', function (Blueprint $table) {
            $table->dropColumn('grant_types');
        });
    }
};
