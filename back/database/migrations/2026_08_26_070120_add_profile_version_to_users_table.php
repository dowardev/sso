<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Monotonic counter bumped on every profile change (name/email/user
            // or encrypted_profile). Lets clients detect stale cached profiles
            // without the server knowing the profile contents.
            $table->unsignedBigInteger('profile_version')->default(0)->after('encryption_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_version');
        });
    }
};
