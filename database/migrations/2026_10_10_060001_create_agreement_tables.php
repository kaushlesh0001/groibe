<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->unsignedInteger('version');
            $table->string('title');
            $table->longText('body_html');
            $table->boolean('is_active')->default(false);
            $table->timestamp('effective_from')->nullable();
            $table->timestamps();
            $table->unique(['type', 'version']);
            $table->index(['type', 'is_active']);
        });

        // Acceptances are legal evidence: users and templates are never cascaded away.
        Schema::create('agreement_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('agreement_templates')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_acceptances');
        Schema::dropIfExists('agreement_templates');
    }
};
