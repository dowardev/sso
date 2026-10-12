<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LBDS-303: persist the OIDC `nonce` from /oauth/authorize on the auth
     * code so it can be round-tripped into the issued id_token. The nonce
     * is the only replay protection the RP has against an id_token
     * intercepted in flight, so it must flow through the auth code — not
     * be re-derived from the session — and live as long as the code does.
     */
    public function up(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->string('nonce')->nullable()->after('scope');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->dropColumn('nonce');
        });
    }
};
