<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('note')->nullable();
            $table->string('status', 30)->default('submitted')->index();
            $table->text('qa_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['task_id', 'version']);
        });

        Schema::create('deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->timestamp('shared_at')->nullable();
            $table->timestamps();
        });

        Schema::create('deliverable_submission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['deliverable_id', 'submission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverable_submission');
        Schema::dropIfExists('deliverables');
        Schema::dropIfExists('submissions');
    }
};
