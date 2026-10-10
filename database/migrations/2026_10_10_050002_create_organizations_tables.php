<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 26)->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_name')->nullable();
            $table->string('gstin', 20)->nullable();
            $table->string('website')->nullable();
            $table->string('industry')->nullable();
            $table->string('size_band', 30)->nullable();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('verified_at')->nullable();
            $table->boolean('can_submit_briefs')->default(false);
            $table->boolean('is_public')->default(false)->index();
            $table->boolean('is_featured')->default(false);
            $table->string('zoho_crm_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('organization_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('org_role', 20)->index();
            $table->string('designation')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_users');
        Schema::dropIfExists('organizations');
    }
};
