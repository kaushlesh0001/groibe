<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_roles', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 26)->unique();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->text('description')->nullable();
            $table->json('tasks_list')->nullable();
            $table->json('learning_outcomes')->nullable();
            $table->foreignId('project_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('work_mode', 20)->default('remote');
            $table->unsignedSmallInteger('est_hours_min')->nullable();
            $table->unsignedSmallInteger('est_hours_max')->nullable();
            $table->string('duration_label', 60)->nullable();
            $table->string('compensation_type', 30)->default('paid');
            $table->string('compensation_note')->nullable();
            $table->string('visibility', 20)->default('members_only')->index();
            $table->unsignedSmallInteger('positions_needed')->default(1);
            $table->string('status', 20)->default('draft')->index();
            $table->date('deadline')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'visibility', 'published_at']);
        });

        Schema::create('open_role_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('open_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['open_role_id', 'skill_id']);
        });

        // SCR-018: the admin-managed list that decides who sees an invite_only role.
        Schema::create('open_role_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('open_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['open_role_id', 'user_id']);
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            // Applications are history: a role or contributor with applications cannot be deleted.
            $table->foreignId('open_role_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('submitted')->index();
            $table->text('motivation');
            $table->text('relevant_experience')->nullable();
            $table->text('availability_note')->nullable();
            $table->decimal('expected_amount', 14, 2)->nullable();
            $table->json('proof_of_work_ids')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('shortlisted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['open_role_id', 'user_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('open_roles', function (Blueprint $table) {
                $table->fullText(['title', 'summary']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
        Schema::dropIfExists('open_role_invites');
        Schema::dropIfExists('open_role_skills');
        Schema::dropIfExists('open_roles');
    }
};
