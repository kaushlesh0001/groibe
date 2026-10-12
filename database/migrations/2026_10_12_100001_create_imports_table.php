<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec 7.9 and SCR-027. Files stay on the private disk; the paths here are never served directly.
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index();
            $table->string('original_name');
            $table->string('file_path');
            $table->string('status', 20)->default('uploaded')->index();
            $table->json('mapping')->nullable();
            $table->boolean('dry_run')->default(true);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->string('error_report_path')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
