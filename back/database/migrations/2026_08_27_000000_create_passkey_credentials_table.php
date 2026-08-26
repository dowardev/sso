<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkey_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Base64-encoded credential id (unique per authenticator credential).
            $table->string('credential_id')->unique();
            // Base64-encoded DER public key (ES256/RS256).
            $table->text('public_key');
            // Authenticator transports (e.g. ["usb","internal"]).
            $table->json('transports')->nullable();
            // Human-friendly device label.
            $table->string('device_name')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        // Migrate the single legacy passkey (if any) from users -> passkey_credentials.
        $rows = DB::table('users')
            ->whereNotNull('passkey_credential_id')
            ->whereNotNull('passkey_public_key')
            ->select(['id', 'passkey_credential_id', 'passkey_public_key'])
            ->get();

        foreach ($rows as $row) {
            DB::table('passkey_credentials')->insert([
                'user_id' => $row->id,
                'credential_id' => $row->passkey_credential_id,
                'public_key' => $row->passkey_public_key,
                'transports' => null,
                'device_name' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('passkey_credentials');
    }
};