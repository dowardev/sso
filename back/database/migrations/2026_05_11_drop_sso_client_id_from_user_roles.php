<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles became global-only: a user holds a role once, regardless of the
     * SSO client it was granted for.
     *
     * The `sso_client_id` pivot column itself is intentionally kept: the
     * backend still reads and writes it (`Role::users()` declares it in
     * `withPivot()`, `UserService::updateUserRoles()` attaches it and
     * `UserRoleResource` exposes it), and it is still backed by a foreign
     * key to `sso_clients`. Dropping it is therefore out of scope here.
     *
     * The previous version of this migration tried to drop the column with a
     * bare `ALTER TABLE ... DROP COLUMN` wrapped in a try/catch. On MySQL
     * that statement fails because the column is referenced by the
     * `user_roles_sso_client_id_foreign` constraint, and the failure was
     * swallowed, so the column silently survived while the migration was
     * still recorded as applied. Its `down()` then re-added the column and
     * aborted the whole rollback with "duplicate column name".
     */
    public function up(): void
    {
        // A role is granted once per user. The legacy
        // `user_roles_user_id_role_id_sso_client_id_unique` index stays in
        // place because it is the only index covering `user_id` for the
        // `user_roles_user_id_foreign` constraint.
        if (! Schema::hasIndex('user_roles', 'user_id_role_id_unique')) {
            Schema::table('user_roles', function (Blueprint $table) {
                $table->unique(['user_id', 'role_id'], 'user_id_role_id_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('user_roles', 'user_id_role_id_unique')) {
            Schema::table('user_roles', function (Blueprint $table) {
                $table->dropUnique('user_id_role_id_unique');
            });
        }
    }
};
