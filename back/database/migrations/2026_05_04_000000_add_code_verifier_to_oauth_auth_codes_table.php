<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->string('code_verifier')->nullable()->after('code_challenge_method');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->dropColumn('code_verifier');
        });
    }
};