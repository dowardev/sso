<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('identifier')->index();
            $table->string('ip', 45)->nullable()->index();
            $table->integer('attempts')->default(0);
            $table->timestamp('locked_until')->nullable()->index();
            $table->timestamps();
            $table->unique(['identifier', 'ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
