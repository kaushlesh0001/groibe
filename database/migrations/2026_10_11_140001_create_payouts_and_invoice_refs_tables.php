<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec 7.6: references and statuses only. Money is paid outside the platform, so no bank or card data lives here.
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('INR');
            $table->string('status', 20)->default('planned')->index();
            $table->string('reference', 40)->nullable();
            $table->decimal('tds_amount', 14, 2)->nullable();
            // SCR-024: set when a payout is approved, so a contributor sees only payouts finance has committed to.
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('invoice_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('zoho_invoice_ref');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('INR');
            $table->string('status', 20)->default('draft')->index();
            $table->date('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'zoho_invoice_ref']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_refs');
        Schema::dropIfExists('payouts');
    }
};
