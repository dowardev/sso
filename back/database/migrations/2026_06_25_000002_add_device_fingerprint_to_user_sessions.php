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
            $table->dropColumn('device_fingerprint');
        });

        // MySQL/MariaDB: InnoDB reuses an existing index as the supporting
        // index of a foreign key, and drops the FK's own index when a wider
        // index with the same leading column arrives: the composite
        // (user_id, remember_me) took over `user_sessions_user_id_foreign`.
        // Dropping the composite while the FK still relies on it aborts with
        // MySQL error 1553 ("Cannot drop index ... needed in a foreign key
        // constraint"), so the dedicated index is restored first — which is
        // also the state this table had before this migration ran.
        //
        // SQLite never creates a supporting index for a foreign key and
        // allows dropping the composite unconditionally, so it is left
        // untouched there.
        if (Schema::getConnection()->getDriverName() === 'mysql'
            && ! Schema::hasIndex('user_sessions', 'user_sessions_user_id_foreign')) {
            Schema::table('user_sessions', function (Blueprint $table) {
                $table->index('user_id', 'user_sessions_user_id_foreign');
            });
        }

        Schema::table('user_sessions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'remember_me']);
        });
    }
};
