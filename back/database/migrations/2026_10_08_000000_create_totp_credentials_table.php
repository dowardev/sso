<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One TOTP credential per user (UNIQUE on user_id enforces this).
        // TOTP secrets are encrypted at rest with Laravel's Crypt facade
        // (AES-256-CBC via APP_KEY) — a DB leak must never expose them in
        // plaintext. See TotpService::encryptSecret() / decryptSecret().
        Schema::create('totp_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Encrypted base32 secret. Always decrypt through TotpService
            // (never read this column directly outside the service).
            $table->text('encrypted_secret');
            // NULL until the user verifies their first code with the freshly
            // generated secret. After that, the credential is "active" and
            // the login flow challenges for it.
            $table->timestamp('confirmed_at')->nullable();
            // Anti-reuse: latest accepted time-step for this user. A code
            // presented at a step <= last_used_step is rejected even if it
            // matches the HMAC. Persisted so a server restart does not reset
            // the counter (which would re-allow a replay).
            $table->unsignedBigInteger('last_used_step')->nullable();
            // RFC 6238 §5.1 parameters. Hardcoded for now (no UI to change
            // them yet); kept in the schema so a future upgrade does not need
            // a migration just to surface them.
            $table->unsignedTinyInteger('digits')->default(6);
            $table->unsignedSmallInteger('period')->default(30);
            $table->string('algorithm')->default('SHA1');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('totp_credentials');
    }
};
