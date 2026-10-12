<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LBDS-303 scope plumbing: oauth_auth_codes now stores the space-separated
     * scope string granted at /oauth/authorize time, and sso_clients gains an
     * allowed_scopes JSON column declaring which scopes a client may request.
     * Defaults for existing rows mirror the OIDC core + offline_access set
     * advertised in the discovery doc, so existing clients stay operational
     * without an explicit migration of their config.
     */
    public function up(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->string('scope')->nullable()->after('code_challenge_method');
        });

        Schema::table('sso_clients', function (Blueprint $table) {
            $table->json('allowed_scopes')->nullable()->after('grant_types');
        });

        DB::table('sso_clients')->update([
            'allowed_scopes' => json_encode(['openid', 'profile', 'email', 'offline_access']),
        ]);
    }

    public function down(): void
    {
        Schema::table('sso_clients', function (Blueprint $table) {
            $table->dropColumn('allowed_scopes');
        });

        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
