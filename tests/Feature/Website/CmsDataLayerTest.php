<?php

use App\Enums\ContactMessageStatus;
use App\Enums\FaqAudience;
use App\Enums\FeaturedItemType;
use App\Models\CmsSection;
use App\Models\ContactMessage;
use App\Models\Faq;
use App\Models\FeaturedItem;
use App\Models\Page;
use App\Models\Testimonial;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

it('creates the six website tables with the spec 7.9 columns', function () {
    expect(Schema::hasColumns('cms_sections', ['key', 'title', 'content', 'sort', 'is_active', 'needs_review']))->toBeTrue()
        ->and(Schema::hasColumns('featured_items', ['type', 'item_id', 'sort', 'is_active']))->toBeTrue()
        ->and(Schema::hasColumns('testimonials', ['author_name', 'author_title', 'organization_name', 'quote', 'photo_path', 'is_active', 'sort', 'needs_review']))->toBeTrue()
        ->and(Schema::hasColumns('faqs', ['audience', 'question', 'answer', 'sort', 'is_active', 'needs_review']))->toBeTrue()
        ->and(Schema::hasColumns('pages', ['slug', 'title', 'body_html', 'meta_title', 'meta_description', 'is_active', 'needs_review']))->toBeTrue()
        ->and(Schema::hasColumns('contact_messages', ['name', 'email', 'subject', 'message', 'ip', 'user_agent', 'status', 'handled_by', 'handled_at', 'email_sent_at']))->toBeTrue();
});

it('builds every model from its factory with typed casts', function () {
    $section = CmsSection::factory()->create(['content' => ['heading' => 'Hello', 'items' => [1, 2]]]);
    $featured = FeaturedItem::factory()->of(FeaturedItemType::Contributor, 7)->create();
    $faq = Faq::factory()->audience(FaqAudience::Client)->create();
    $message = ContactMessage::factory()->create();

    expect($section->fresh()->content)->toBe(['heading' => 'Hello', 'items' => [1, 2]])
        ->and($section->fresh()->is_active)->toBeTrue()
        ->and($featured->fresh()->type)->toBe(FeaturedItemType::Contributor)
        ->and($featured->fresh()->item_id)->toBe(7)
        ->and($faq->fresh()->audience)->toBe(FaqAudience::Client)
        ->and($message->fresh()->status)->toBe(ContactMessageStatus::New)
        ->and(Testimonial::factory()->create()->is_active)->toBeTrue()
        ->and(Page::factory()->create()->needs_review)->toBeFalse();
});

it('orders by sort then id and filters active rows', function () {
    $second = CmsSection::factory()->create(['sort' => 2]);
    $first = CmsSection::factory()->create(['sort' => 1]);
    $tie = CmsSection::factory()->create(['sort' => 2]);
    CmsSection::factory()->inactive()->create(['sort' => 0]);

    expect(CmsSection::query()->active()->ordered()->pluck('id')->all())->toBe([$first->id, $second->id, $tie->id]);

    Faq::factory()->audience(FaqAudience::Client)->create();
    Faq::factory()->audience(FaqAudience::General)->create();
    Faq::factory()->audience(FaqAudience::Client)->inactive()->create();

    expect(Faq::query()->active()->forAudience(FaqAudience::Client)->count())->toBe(1);
});

it('marks unreviewed seeded content as inactive and flagged', function () {
    foreach ([CmsSection::factory(), Testimonial::factory(), Faq::factory(), Page::factory()] as $factory) {
        $row = $factory->needsReview()->create();

        expect($row->is_active)->toBeFalse()->and($row->needs_review)->toBeTrue();
    }
});

it('keeps keys, slugs and featured picks unique', function () {
    CmsSection::factory()->create(['key' => 'hero']);
    Page::factory()->create(['slug' => 'about-us']);
    FeaturedItem::factory()->of(FeaturedItemType::OpenRole, 3)->create();

    expect(fn () => CmsSection::factory()->create(['key' => 'hero']))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => Page::factory()->create(['slug' => 'about-us']))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => FeaturedItem::factory()->of(FeaturedItemType::OpenRole, 3)->create())->toThrow(UniqueConstraintViolationException::class);

    // The same id under a different type is a different item.
    expect(FeaturedItem::factory()->of(FeaturedItemType::Project, 3)->create()->exists)->toBeTrue();
});

it('finds messages that still need handling', function () {
    ContactMessage::factory()->create();
    ContactMessage::factory()->status(ContactMessageStatus::Read)->create();
    ContactMessage::factory()->status(ContactMessageStatus::Handled)->create();

    expect(ContactMessage::query()->unhandled()->count())->toBe(2);
});

it('logs contact message status moves without the message or the sender', function () {
    $message = ContactMessage::factory()->create(['message' => 'Private words', 'email' => 'visitor@example.test']);
    $message->update(['status' => ContactMessageStatus::Read]);

    $log = Activity::query()->where('subject_type', ContactMessage::class)->latest('id')->first();

    expect(json_encode($log->properties))->toContain('read')
        ->not->toContain('Private words')
        ->not->toContain('visitor@example.test');
});
