<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LBDS-303 step 5: per-client claim overrides. The shape lives in
     * SsoClient::effectiveClaimOverrides() and is documented in the
     * controller / JWT service that consumes it. The column is nullable:
     * null means "use the OIDC defaults", which is what every client
     * predating LBDS-303 gets without any config migration.
     */
    public function up(): void
    {
        Schema::table('sso_clients', function (Blueprint $table) {
            $table->json('claim_overrides')->nullable()->after('allowed_scopes');
        });
    }

    public function down(): void
    {
        Schema::table('sso_clients', function (Blueprint $table) {
            $table->dropColumn('claim_overrides');
        });
    }
};
