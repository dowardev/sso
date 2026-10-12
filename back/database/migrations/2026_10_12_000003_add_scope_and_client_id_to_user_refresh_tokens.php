<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LBDS-303 follow-up: refresh tokens need to carry the granted
     * `scope` and the originating `client_id` so a rotation preserves
     * the OIDC contract (scope filtering on /oauth/userinfo, per-client
     * claim_overrides resolution). Pre-this-migration rows stay null
     * and the JwtService falls back to omitting those claims on the
     * rotated access token, matching the old behaviour for tokens that
     * were issued before the fix landed.
     */
    public function up(): void
    {
        Schema::table('user_refresh_tokens', function (Blueprint $table) {
            $table->string('scope')->nullable()->after('dpop_jkt');
            $table->string('client_id')->nullable()->after('scope');
        });
    }

    public function down(): void
    {
        Schema::table('user_refresh_tokens', function (Blueprint $table) {
            $table->dropColumn(['scope', 'client_id']);
        });
    }
};
