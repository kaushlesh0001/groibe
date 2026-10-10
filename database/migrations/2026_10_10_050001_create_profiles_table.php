<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('headline')->nullable();
            $table->text('bio')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('portfolio_url')->nullable();
            $table->string('availability', 20)->nullable();
            $table->unsignedSmallInteger('hours_per_week')->nullable();
            $table->json('work_modes')->nullable();
            $table->unsignedSmallInteger('experience_years')->nullable();
            $table->json('languages')->nullable();
            $table->boolean('is_public')->default(false)->index();
            $table->string('slug')->nullable()->unique();
            $table->string('onboarding_state', 30)->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('profiles', function (Blueprint $table) {
                $table->fullText(['headline', 'bio']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
