<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 26)->unique();
            $table->foreignId('brief_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->text('scope_summary')->nullable();
            $table->foreignId('project_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->decimal('contract_value', 14, 2)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->foreignId('delivery_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('client_can_view_shortlist')->default(false);
            $table->string('showcase_consent', 20)->default('none');
            $table->string('zoho_invoice_ref')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index('organization_id');
        });

        Schema::create('project_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'skill_id']);
        });

        Schema::create('sows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft')->index();
            $table->string('title');
            $table->longText('body_html');
            $table->json('body_variables')->nullable();
            $table->decimal('total_value', 14, 2)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->text('payment_terms')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accepted_ip', 45)->nullable();
            $table->string('accepted_user_agent', 500)->nullable();
            $table->unsignedBigInteger('superseded_by')->nullable()->index();
            $table->timestamps();
            $table->unique(['project_id', 'version']);
        });

        // Circular references are added after both tables exist.
        Schema::table('briefs', function (Blueprint $table) {
            $table->foreign('converted_project_id')->references('id')->on('projects')->nullOnDelete();
        });

        Schema::table('agreement_acceptances', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });

        Schema::table('sows', function (Blueprint $table) {
            $table->foreign('superseded_by')->references('id')->on('sows')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('briefs', function (Blueprint $table) {
            $table->dropForeign(['converted_project_id']);
        });

        Schema::table('agreement_acceptances', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::dropIfExists('sows');
        Schema::dropIfExists('project_skills');
        Schema::dropIfExists('projects');
    }
};
