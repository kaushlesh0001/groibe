<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec 7.9, 17.2 and SCR-028. The person's row is soft-deleted and anonymised, never removed, so `restrictOnDelete` keeps
        // a request, and the payouts and audit entries that point at the person, attached to a real row.
        Schema::create('data_requests', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 20)->index();
            $table->string('status', 20)->default('open')->index();
            $table->text('note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_requests');
    }
};
