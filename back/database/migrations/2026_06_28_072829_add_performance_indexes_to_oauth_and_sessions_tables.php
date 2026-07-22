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
            $table->index('last_used_at');
        });

        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->index('expires_at');
        });

        Schema::table('oauth_client_authorizations', function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            $table->dropIndex(['last_used_at']);
        });

        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
        });

        Schema::table('oauth_client_authorizations', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};
