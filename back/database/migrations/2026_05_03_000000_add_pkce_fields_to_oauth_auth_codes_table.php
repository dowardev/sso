<?php
// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->string('code_challenge')->nullable()->after('redirect_uri');
            $table->string('code_challenge_method')->nullable()->after('code_challenge');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->dropColumn(['code_challenge', 'code_challenge_method']);
        });
    }
};