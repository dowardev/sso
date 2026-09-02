<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passkey_credentials', function (Blueprint $table) {
            // WebAuthn userHandle (the user entity id string) — required to
            // verify assertions from resident (discoverable) credentials.
            //
            // Nullable and NOT backfilled: passkeys registered before LBDS-208
            // either crashed at registration (advanced/ZKE: null user/email) or
            // stored username/email as userHandle (legacy basic) — neither is
            // a valid user id, and there are no production rows to migrate.
            // New registrations persist the correct value via storeCredential.
            $table->string('user_handle')->nullable()->after('public_key');
        });
    }

    public function down(): void
    {
        Schema::table('passkey_credentials', function (Blueprint $table) {
            $table->dropColumn('user_handle');
        });
    }
};
