<?php
// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csp_reports', function (Blueprint $table) {
            $table->id();
            $table->text('blocked_uri')->nullable();
            $table->text('document_uri')->nullable();
            $table->text('referrer')->nullable();
            $table->text('violated_directive')->nullable();
            $table->text('effective_directive')->nullable();
            $table->text('original_policy')->nullable();
            $table->text('source_file')->nullable();
            $table->integer('line_number')->nullable();
            $table->string('script_sample', 512)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csp_reports');
    }
};
