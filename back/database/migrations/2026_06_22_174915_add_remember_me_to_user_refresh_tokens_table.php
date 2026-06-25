<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_refresh_tokens', function (Blueprint $table) {
            $table->boolean('remember_me')->default(false)->after('rotated_from_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_refresh_tokens', function (Blueprint $table) {
            $table->dropColumn('remember_me');
        });
    }
};
