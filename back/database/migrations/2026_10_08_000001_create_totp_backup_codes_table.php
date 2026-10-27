<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One-time recovery codes for accounts that lose their TOTP device.
        // Stored as Argon2id hashes — never readable in plaintext, just like
        // the password column. `used_at` is set when a code is consumed (so
        // any leftover codes stay valid; the regeneration flow deletes the
        // user's previous set before inserting the new one).
        Schema::create('totp_backup_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            // Per-user lookup is the hot path: "is this code valid?" and
            // "list unused codes". The user_id index covers both.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('totp_backup_codes');
    }
};
