<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // login_id = HMAC(username) for advanced users (allows lookup without storing plaintext username)
            $table->string('login_id')->unique()->nullable()->after('user');

            // encryption_level: 'basic' (plaintext profile) or 'advanced' (encrypted profile)
            $table->enum('encryption_level', ['basic', 'advanced'])->default('basic')->after('login_id');

            // Key_A derivation salt (PBKDF2 ≥1M iterations)
            $table->string('salt_a')->nullable()->after('encryption_level');

            // Key_R derivation salt (for recovery)
            $table->string('salt_r')->nullable()->after('salt_a');

            // DEK wrapped by Key_A (AES-KW)
            $table->text('wrapped_dek_a')->nullable()->after('salt_r');

            // DEK wrapped by Key_R (AES-KW)
            $table->text('wrapped_dek_r')->nullable()->after('wrapped_dek_a');

            // Encrypted user profile (AES-256-GCM with DEK)
            $table->longText('encrypted_profile')->nullable()->after('wrapped_dek_r');

            // HMAC(Key_R, "verify") for seed phrase verification
            $table->string('encrypted_seed_verification')->nullable()->after('encrypted_profile');

            // Passkey / WebAuthn columns
            $table->string('passkey_public_key')->nullable()->after('encrypted_seed_verification');
            $table->string('passkey_credential_id')->nullable()->after('passkey_public_key');

            // Make name, user, email, password nullable for advanced users
            // (advanced users store only encrypted profile blobs)
            $table->string('name')->nullable()->change();
            $table->string('user')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Advanced users have name/user/email/password NULL (their profile is
        // encrypted); normalize them to an empty string so restoring the NOT
        // NULL constraint cannot fail on rollback.
        DB::table('users')->whereNull('name')->update(['name' => '']);
        DB::table('users')->whereNull('user')->update(['user' => '']);
        DB::table('users')->whereNull('email')->update(['email' => '']);
        DB::table('users')->whereNull('password')->update(['password' => '']);

        Schema::table('users', function (Blueprint $table) {
            // Restore required constraints before dropping columns
            $table->string('name')->nullable(false)->change();
            $table->string('user')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });

        // SQLite refuses to drop a column that a unique index still covers
        // ("error in index users_login_id_unique after drop column"), while
        // MySQL drops that index implicitly along with the column. Dropping
        // the index explicitly keeps the rollback working on both drivers.
        if (Schema::hasIndex('users', 'users_login_id_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_login_id_unique');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'login_id',
                'encryption_level',
                'salt_a',
                'salt_r',
                'wrapped_dek_a',
                'wrapped_dek_r',
                'encrypted_profile',
                'encrypted_seed_verification',
                'passkey_public_key',
                'passkey_credential_id',
            ]);
        });
    }
};
