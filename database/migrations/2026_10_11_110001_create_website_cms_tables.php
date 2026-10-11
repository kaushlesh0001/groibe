<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec 7.9. `needs_review` (SCR-021) marks seeded content whose wording the founder has not approved yet.
        // Such rows are seeded inactive, and the admin screens label them, so nothing unreviewed reaches a public page.
        Schema::create('cms_sections', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('title');
            $table->json('content')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('needs_review')->default(false);
            $table->timestamps();
            $table->index(['is_active', 'sort']);
        });

        // `item_id` points at a row of the table named by `type`. No foreign key: the public read resolves it
        // each time and skips anything missing or no longer public.
        Schema::create('featured_items', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->unsignedBigInteger('item_id');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['type', 'item_id']);
            $table->index(['type', 'is_active', 'sort']);
        });

        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('author_name');
            $table->string('author_title')->nullable();
            $table->string('organization_name')->nullable();
            $table->text('quote');
            $table->string('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('needs_review')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort']);
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('audience', 20)->default('general');
            $table->string('question');
            $table->text('answer');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('needs_review')->default(false);
            $table->timestamps();
            $table->index(['audience', 'is_active', 'sort']);
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('title');
            $table->longText('body_html')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('needs_review')->default(false);
            $table->timestamps();
            $table->index('is_active');
        });

        // Spec 10.2 and 13 name the Contact Messages screen but 7.9 defines no columns (SCR-021). Messages are
        // stored first and emailed after, so `email_sent_at` shows whether the admin was told.
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('subject')->nullable();
            $table->text('message');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('featured_items');
        Schema::dropIfExists('cms_sections');
    }
};
