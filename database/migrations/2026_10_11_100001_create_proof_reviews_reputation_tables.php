<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec 7.5. `slug` (SCR-020) is the public URL key for /work/{slug}, generated when the item is first published.
        Schema::create('proof_of_work', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 26)->unique();
            $table->string('slug')->nullable()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('role_title')->nullable();
            $table->text('summary')->nullable();
            $table->text('contribution_text')->nullable();
            $table->json('outcome_metrics')->nullable();
            $table->string('visibility', 20)->default('private');
            $table->string('status', 20)->default('draft');
            $table->string('client_display', 20)->default('anonymous');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'visibility']);
            $table->index('status');
            // One draft per contributor per project, so closing twice cannot duplicate (SCR-020).
            $table->unique(['user_id', 'project_id']);
        });

        Schema::create('proof_of_work_skill', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proof_of_work_id')->constrained('proof_of_work')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['proof_of_work_id', 'skill_id']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('reviewee_type', 20);
            $table->unsignedBigInteger('reviewee_id');
            $table->string('direction', 40);
            // Contributor criteria (client_to_contributor, admin_to_contributor), 1 to 5.
            $table->unsignedTinyInteger('quality')->nullable();
            $table->unsignedTinyInteger('communication')->nullable();
            $table->unsignedTinyInteger('reliability')->nullable();
            $table->unsignedTinyInteger('timeliness')->nullable();
            // Organization criteria (contributor_to_client). `communication` is shared.
            $table->unsignedTinyInteger('clarity')->nullable();
            $table->unsignedTinyInteger('professionalism')->nullable();
            $table->unsignedTinyInteger('feedback_time')->nullable();
            $table->unsignedTinyInteger('fairness')->nullable();
            $table->text('comment')->nullable();
            $table->string('status', 30)->default('pending_moderation');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'reviewer_id', 'reviewee_type', 'reviewee_id'], 'reviews_unique_per_reviewee');
            $table->index(['reviewee_type', 'reviewee_id', 'status']);
            $table->index('status');
        });

        Schema::create('reputation_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('projects_completed')->default(0);
            $table->unsignedInteger('tasks_approved_first_pass')->default(0);
            $table->unsignedInteger('tasks_total')->default(0);
            // SCR-020: the on-time denominator, because tasks without a due date are left out of the on-time share.
            $table->unsignedInteger('tasks_with_due_date')->default(0);
            $table->unsignedInteger('on_time_tasks')->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->decimal('avg_rating', 3, 2)->nullable();
            $table->unsignedInteger('verified_skills_count')->default(0);
            $table->timestamp('recomputed_at')->nullable();
            $table->timestamps();
        });

        // SCR-020: spec 7.5 says "Org equivalent" without columns. Admin-only visibility in v1.
        Schema::create('organization_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('projects_completed')->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->decimal('avg_rating', 3, 2)->nullable();
            $table->decimal('avg_clarity', 3, 2)->nullable();
            $table->decimal('avg_communication', 3, 2)->nullable();
            $table->decimal('avg_professionalism', 3, 2)->nullable();
            $table->decimal('avg_feedback_time', 3, 2)->nullable();
            $table->decimal('avg_fairness', 3, 2)->nullable();
            $table->timestamp('recomputed_at')->nullable();
            $table->timestamps();
        });

        // Spec 7.10: FULLTEXT on proof_of_work(title, summary). MySQL and MariaDB only; tests run on SQLite.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE proof_of_work ADD FULLTEXT proof_of_work_title_summary_fulltext (title, summary)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_stats');
        Schema::dropIfExists('reputation_facts');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('proof_of_work_skill');
        Schema::dropIfExists('proof_of_work');
    }
};
