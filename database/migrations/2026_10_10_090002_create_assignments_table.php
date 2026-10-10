<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role_title');
            $table->string('status', 20)->default('invited')->index();
            $table->string('rate_type', 20)->default('fixed');
            $table->decimal('agreed_amount', 14, 2)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('removed_reason')->nullable();
            $table->timestamps();
            // Uniqueness across open states (invited, active) is enforced in code (spec 7.4).
            $table->index(['project_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
