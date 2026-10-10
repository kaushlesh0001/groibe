<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('briefs', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('problem')->nullable();
            $table->text('desired_outcome')->nullable();
            $table->foreignId('project_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('budget_band', 30)->nullable();
            $table->string('timeline_band', 30)->nullable();
            $table->date('target_start')->nullable();
            $table->date('deadline')->nullable();
            $table->string('confidentiality', 20)->default('standard');
            $table->string('status', 20)->default('draft');
            $table->text('needs_info_note')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('users')->nullOnDelete();
            // Foreign key to projects is added in the projects migration (circular reference).
            $table->unsignedBigInteger('converted_project_id')->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['status', 'organization_id']);
        });

        Schema::create('brief_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brief_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['brief_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brief_skills');
        Schema::dropIfExists('briefs');
    }
};
