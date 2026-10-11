<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 26)->unique();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20)->index();
            $table->string('subject');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('last_message_at', 6)->nullable()->index();
            $table->timestamps();
            // One project thread per project (spec 14). Support threads have no project, and NULLs never collide.
            $table->unique(['project_id', 'type']);
        });

        Schema::create('thread_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at', 6)->nullable();
            $table->timestamps();
            $table->unique(['thread_id', 'user_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamp('created_at', 6)->nullable();
            $table->timestamp('updated_at', 6)->nullable();
            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('thread_participants');
        Schema::dropIfExists('threads');
    }
};
