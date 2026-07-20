<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_client_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('sso_client_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['authorized', 'denied', 'revoked'])->default('authorized');
            $table->timestamps();

            $table->unique(['user_id', 'sso_client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_client_authorizations');
    }
};
