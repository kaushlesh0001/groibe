<?php

use App\Actions\Website\SetContactMessageStatus;
use App\Enums\AdminRole;
use App\Enums\ContactMessageStatus;
use App\Enums\FaqAudience;
use App\Enums\FeaturedItemType;
use App\Filament\Admin\Resources\ContactMessages\Pages\ListContactMessages;
use App\Filament\Admin\Resources\ContactMessages\Pages\ViewContactMessage;
use App\Filament\Admin\Resources\Faqs\Pages\CreateFaq;
use App\Filament\Admin\Resources\Faqs\Pages\ListFaqs;
use App\Filament\Admin\Resources\FeaturedItems\Pages\CreateFeaturedItem;
use App\Filament\Admin\Resources\FeaturedItems\Pages\ListFeaturedItems;
use App\Filament\Admin\Resources\HomepageSections\HomepageSectionResource;
use App\Filament\Admin\Resources\HomepageSections\Pages\CreateHomepageSection;
use App\Filament\Admin\Resources\HomepageSections\Pages\EditHomepageSection;
use App\Filament\Admin\Resources\HomepageSections\Pages\ListHomepageSections;
use App\Filament\Admin\Resources\Pages\Pages\CreatePage;
use App\Filament\Admin\Resources\Pages\Pages\EditPage;
use App\Filament\Admin\Resources\Pages\Pages\ListPages;
use App\Filament\Admin\Resources\Testimonials\Pages\CreateTestimonial;
use App\Filament\Admin\Resources\Testimonials\Pages\ListTestimonials;
use App\Models\CmsSection;
use App\Models\ContactMessage;
use App\Models\Faq;
use App\Models\FeaturedItem;
use App\Models\OpenRole;
use App\Models\Page;
use App\Models\Testimonial;
use App\Rules\PublicCopy;
use App\Services\Website\FeaturedItemResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

$cms = fn () => adminUser(AdminRole::CommunityManager);

describe('access', function () use ($cms) {
    it('opens every website screen for a community manager and a super admin', function (string $url) use ($cms) {
        asUser($cms())->get($url)->assertOk();
        asUser(adminUser())->get($url)->assertOk();
    })->with([
        '/admin/homepage-sections', '/admin/featured-items', '/admin/testimonials', '/admin/faqs', '/admin/pages', '/admin/contact-messages',
    ]);

    it('keeps every other role and guest out of the website screens', function (string $url) {
        foreach ([adminUser(AdminRole::DeliveryManager), adminUser(AdminRole::FinanceOps), clientUser(), contributorUser()] as $user) {
            asUser($user)->get($url)->assertForbidden();
        }
    })->with([
        '/admin/homepage-sections', '/admin/featured-items', '/admin/testimonials', '/admin/faqs', '/admin/pages', '/admin/contact-messages',
    ]);

    it('sends a guest to the sign-in page', function (string $url) {
        $this->get($url)->assertRedirect();
    })->with([
        '/admin/homepage-sections', '/admin/featured-items', '/admin/testimonials', '/admin/faqs', '/admin/pages', '/admin/contact-messages',
    ]);

    it('applies the Website CMS row of the matrix to the content models', function () use ($cms) {
        $models = [CmsSection::factory()->create(), Testimonial::factory()->create(), Faq::factory()->create(), Page::factory()->create(), FeaturedItem::factory()->create()];

        foreach ($models as $record) {
            expect($cms()->can('create', $record::class))->toBeTrue()
                ->and($cms()->can('update', $record))->toBeTrue()
                ->and($cms()->can('delete', $record))->toBeTrue()
                ->and(adminUser()->can('delete', $record))->toBeTrue()
                ->and(adminUser(AdminRole::DeliveryManager)->can('viewAny', $record::class))->toBeFalse()
                ->and(adminUser(AdminRole::FinanceOps)->can('update', $record))->toBeFalse()
                ->and(clientUser()->can('viewAny', $record::class))->toBeFalse();
        }
    });

    it('lets community managers read and update contact messages but only a super admin delete or create one', function () use ($cms) {
        $message = ContactMessage::factory()->create();

        expect($cms()->can('view', $message))->toBeTrue()
            ->and($cms()->can('update', $message))->toBeTrue()
            ->and($cms()->can('delete', $message))->toBeFalse()
            ->and($cms()->can('create', ContactMessage::class))->toBeFalse()
            ->and(adminUser()->can('delete', $message))->toBeTrue()
            ->and(adminUser()->can('create', ContactMessage::class))->toBeFalse()
            ->and(adminUser(AdminRole::DeliveryManager)->can('view', $message))->toBeFalse()
            ->and(adminUser(AdminRole::FinanceOps)->can('view', $message))->toBeFalse();
    });
});

describe('review gate', function () {
    it('keeps unreviewed content inactive however it is saved', function () {
        $faq = Faq::factory()->create(['is_active' => true, 'needs_review' => true]);

        expect($faq->fresh()->is_active)->toBeFalse();

        $faq->update(['is_active' => true]);

        expect($faq->fresh()->is_active)->toBeFalse()->and(Faq::query()->active()->count())->toBe(0);

        $faq->update(['needs_review' => false, 'is_active' => true]);

        expect(Faq::query()->active()->count())->toBe(1);
    });

    it('leaves a row out of the public scope while it is flagged, even if the column says active', function () {
        $page = Page::factory()->create();
        DB::table('pages')->where('id', $page->id)->update(['needs_review' => true]);

        expect(Page::query()->active()->count())->toBe(0);
    });
});

describe('homepage sections', function () use ($cms) {
    it('lists sections in order and reorders them by drag', function () use ($cms) {
        $b = CmsSection::factory()->create(['key' => 'faq', 'sort' => 2]);
        $a = CmsSection::factory()->create(['key' => 'hero', 'sort' => 1]);

        Livewire::actingAs($cms())->test(ListHomepageSections::class)->assertCanSeeTableRecords([$a, $b], inOrder: true)
            ->call('reorderTable', [$b->id, $a->id]);

        expect(CmsSection::query()->ordered()->pluck('key')->all())->toBe(['faq', 'hero']);
    });

    it('switches a section on and off from the list', function () use ($cms) {
        $section = CmsSection::factory()->create(['is_active' => true]);

        Livewire::actingAs($cms())->test(ListHomepageSections::class)->call('updateTableColumnState', 'is_active', (string) $section->id, false);

        expect($section->fresh()->is_active)->toBeFalse();
    });

    it('saves the content of a section as JSON', function () use ($cms) {
        $section = CmsSection::factory()->create(['key' => 'hero', 'content' => []]);

        Livewire::actingAs($cms())->test(EditHomepageSection::class, ['record' => $section->getKey()])
            ->fillForm(['content.heading' => 'Where Ideas Find', 'content.highlight' => 'People', 'content.primary_url' => '/opportunities'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($section->fresh()->content)->toMatchArray(['heading' => 'Where Ideas Find', 'highlight' => 'People', 'primary_url' => '/opportunities']);
    });

    it('refuses an em dash, AI wording and an unsafe link in the content', function (string $field, string $value) use ($cms) {
        $section = CmsSection::factory()->create(['key' => 'hero', 'content' => []]);

        Livewire::actingAs($cms())->test(EditHomepageSection::class, ['record' => $section->getKey()])
            ->fillForm(["content.{$field}" => $value])
            ->call('save')
            ->assertHasFormErrors(["content.{$field}"]);
    })->with([
        'em dash' => ['subheading', "Fast \u{2014} and simple"],
        'AI wording' => ['subheading', 'Powered by AI matching'],
        'javascript link' => ['primary_url', 'javascript:alert(1)'],
        'http link' => ['primary_url', 'http://example.test'],
    ]);

    it('only offers sections that do not exist yet when creating, and refuses a repeated key', function () use ($cms) {
        CmsSection::factory()->create(['key' => 'hero']);

        Livewire::actingAs($cms())->test(CreateHomepageSection::class)
            ->fillForm(['key' => 'hero', 'title' => 'Hero again', 'sort' => 1])
            ->call('create')
            ->assertHasFormErrors(['key']);

        Livewire::actingAs($cms())->test(CreateHomepageSection::class)
            ->fillForm(['key' => 'stats', 'title' => 'Stats', 'sort' => 8, 'content.override_projects' => 12])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(CmsSection::query()->where('key', 'stats')->value('content'))->toMatchArray(['override_projects' => 12]);
    });

    it('cannot delete a section, only hide it', function () use ($cms) {
        $section = CmsSection::factory()->create();

        expect(HomepageSectionResource::canDelete($section))->toBeFalse();
        Livewire::actingAs($cms())->test(EditHomepageSection::class, ['record' => $section->getKey()])->assertActionDoesNotExist('delete');
    });

    it('labels seeded sections that need review, keeps them off and lets an admin approve the wording', function () use ($cms) {
        $section = CmsSection::factory()->needsReview()->create(['key' => 'testimonials']);

        $list = Livewire::actingAs($cms())->test(ListHomepageSections::class)
            ->assertSee('Needs review')
            ->assertTableActionVisible('approveWording', $section);

        // The switch is locked while the flag is on.
        $list->call('updateTableColumnState', 'is_active', (string) $section->id, true);
        expect($section->fresh()->is_active)->toBeFalse();

        $list->callTableAction('approveWording', $section);

        expect($section->fresh()->needs_review)->toBeFalse()->and($section->fresh()->is_active)->toBeFalse();

        Livewire::actingAs($cms())->test(ListHomepageSections::class)->assertTableActionHidden('approveWording', $section)
            ->call('updateTableColumnState', 'is_active', (string) $section->id, true);

        expect($section->fresh()->is_active)->toBeTrue();
    });
});

describe('featured items', function () use ($cms) {
    it('offers and accepts only items the public can see', function () use ($cms) {
        $live = OpenRole::factory()->published()->public()->create(['title' => 'Survey analyst']);
        $draft = OpenRole::factory()->create(['title' => 'Hidden draft']);
        $members = OpenRole::factory()->published()->create(['title' => 'Members only']);

        expect(app(FeaturedItemResolver::class)->options(FeaturedItemType::OpenRole))->toBe([$live->id => 'Survey analyst']);

        Livewire::actingAs($cms())->test(CreateFeaturedItem::class)
            ->fillForm(['type' => FeaturedItemType::OpenRole->value, 'item_id' => $draft->id, 'sort' => 1])
            ->call('create')
            ->assertHasFormErrors(['item_id']);

        Livewire::actingAs($cms())->test(CreateFeaturedItem::class)
            ->fillForm(['type' => FeaturedItemType::OpenRole->value, 'item_id' => $members->id, 'sort' => 1])
            ->call('create')
            ->assertHasFormErrors(['item_id']);

        Livewire::actingAs($cms())->test(CreateFeaturedItem::class)
            ->fillForm(['type' => FeaturedItemType::OpenRole->value, 'item_id' => $live->id, 'sort' => 1])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(FeaturedItem::query()->count())->toBe(1);
    });

    it('refuses to feature the same item twice', function () use ($cms) {
        $role = OpenRole::factory()->published()->public()->create();
        FeaturedItem::factory()->of(FeaturedItemType::OpenRole, $role->id)->create();

        Livewire::actingAs($cms())->test(CreateFeaturedItem::class)
            ->fillForm(['type' => FeaturedItemType::OpenRole->value, 'item_id' => $role->id, 'sort' => 2])
            ->call('create')
            ->assertHasFormErrors(['item_id']);
    });

    it('shows the title of each pick and flags one that is gone or no longer public', function () use ($cms) {
        $live = OpenRole::factory()->published()->public()->create(['title' => 'Survey analyst']);
        $gone = FeaturedItem::factory()->of(FeaturedItemType::Organization, 9999)->create();
        $ok = FeaturedItem::factory()->of(FeaturedItemType::OpenRole, $live->id)->create();

        Livewire::actingAs($cms())->test(ListFeaturedItems::class)
            ->assertCanSeeTableRecords([$ok, $gone])
            ->assertSee('Survey analyst')
            ->assertSee('Missing or no longer public');

        $live->update(['status' => 'closed']);

        Livewire::actingAs($cms())->test(ListFeaturedItems::class)->assertDontSee('Survey analyst');
    });

    it('looks up labels with one query per type, however many picks there are', function () {
        $roles = OpenRole::factory()->count(6)->published()->public()->create();
        $items = $roles->map(fn (OpenRole $role) => FeaturedItem::factory()->of(FeaturedItemType::OpenRole, $role->id)->create());

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(FeaturedItemResolver::class)->labels($items);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queries)->toBe(1);
    });
});

describe('testimonials, FAQs and pages', function () use ($cms) {
    it('creates a testimonial and keeps a quote with an em dash out', function () use ($cms) {
        Livewire::actingAs($cms())->test(CreateTestimonial::class)
            ->fillForm(['author_name' => 'A Client', 'quote' => "Great \u{2014} truly", 'sort' => 1])
            ->call('create')
            ->assertHasFormErrors(['quote']);

        Livewire::actingAs($cms())->test(CreateTestimonial::class)
            ->fillForm(['author_name' => 'A Client', 'quote' => 'Delivered on time.', 'sort' => 1, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Testimonial::query()->active()->count())->toBe(1);
    });

    it('creates a FAQ for an audience and filters the list', function () use ($cms) {
        Livewire::actingAs($cms())->test(CreateFaq::class)
            ->fillForm(['audience' => FaqAudience::Client->value, 'question' => 'How do I start?', 'answer' => 'Submit a brief.', 'sort' => 1, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $general = Faq::factory()->create();
        $client = Faq::query()->where('audience', 'client')->first();

        Livewire::actingAs($cms())->test(ListFaqs::class)
            ->filterTable('audience', 'client')
            ->assertCanSeeTableRecords([$client])
            ->assertCanNotSeeTableRecords([$general]);
    });

    it('creates a page, keeps reserved and repeated addresses out and locks the address afterwards', function () use ($cms) {
        foreach (['admin', 'terms', 'work'] as $reserved) {
            Livewire::actingAs($cms())->test(CreatePage::class)
                ->fillForm(['title' => 'Nope', 'slug' => $reserved, 'is_active' => true])
                ->call('create')
                ->assertHasFormErrors(['slug']);
        }

        Livewire::actingAs($cms())->test(CreatePage::class)
            ->fillForm(['title' => 'Careers', 'slug' => 'careers', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::actingAs($cms())->test(CreatePage::class)
            ->fillForm(['title' => 'Careers two', 'slug' => 'careers', 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        $page = Page::query()->where('slug', 'careers')->firstOrFail();

        Livewire::actingAs($cms())->test(EditPage::class, ['record' => $page->getKey()])
            ->assertFormFieldIsDisabled('slug')
            ->fillForm(['title' => 'Careers at Groibe'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($page->fresh()->slug)->toBe('careers')->and($page->fresh()->title)->toBe('Careers at Groibe');
    });

    it('refuses a bad address shape and an em dash in the page body', function () use ($cms) {
        Livewire::actingAs($cms())->test(CreatePage::class)
            ->fillForm(['title' => 'Bad', 'slug' => 'Not A Slug', 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::actingAs($cms())->test(CreatePage::class)
            ->fillForm(['title' => 'Body', 'slug' => 'body-page', 'body_html' => "<p>Fast \u{2014} simple</p>", 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['body_html']);
    });

    it('keeps a flagged page off while it needs review', function () use ($cms) {
        $page = Page::factory()->needsReview()->create();

        Livewire::actingAs($cms())->test(ListPages::class)->assertSee('Needs review')->callTableAction('approveWording', $page);

        expect($page->fresh()->needs_review)->toBeFalse()->and(Page::query()->active()->count())->toBe(0);
    });

    it('lists testimonials flagged for review with their label', function () use ($cms) {
        $flagged = Testimonial::factory()->needsReview()->create();

        Livewire::actingAs($cms())->test(ListTestimonials::class)->assertCanSeeTableRecords([$flagged])->assertSee('Needs review');
    });
});

describe('contact messages', function () use ($cms) {
    it('lets a community manager mark a message read, handled and reopened', function () use ($cms) {
        $admin = $cms();
        $message = ContactMessage::factory()->create();

        $page = Livewire::actingAs($admin)->test(ViewContactMessage::class, ['record' => $message->getKey()])
            ->assertSee($message->message)
            ->callAction('markRead');

        expect($message->fresh()->status)->toBe(ContactMessageStatus::Read);

        $page->callAction('markHandled');

        expect($message->fresh()->status)->toBe(ContactMessageStatus::Handled)
            ->and($message->fresh()->handled_by)->toBe($admin->id)
            ->and($message->fresh()->handled_at)->not->toBeNull();

        $page->callAction('reopen');

        expect($message->fresh()->status)->toBe(ContactMessageStatus::New)
            ->and($message->fresh()->handled_by)->toBeNull();
    });

    it('lists new messages by default and offers actions only where they change something', function () use ($cms) {
        $new = ContactMessage::factory()->create();
        $handled = ContactMessage::factory()->status(ContactMessageStatus::Handled)->create();

        Livewire::actingAs($cms())->test(ListContactMessages::class)
            ->assertCanSeeTableRecords([$new])
            ->assertCanNotSeeTableRecords([$handled])
            ->assertTableActionVisible('markRead', $new)
            ->assertTableActionHidden('reopen', $new);
    });

    it('refuses the status action to anyone without the update right', function () {
        $message = ContactMessage::factory()->create();

        expect(fn () => app(SetContactMessageStatus::class)($message, ContactMessageStatus::Read, adminUser(AdminRole::DeliveryManager)))
            ->toThrow(AuthorizationException::class)
            ->and($message->fresh()->status)->toBe(ContactMessageStatus::New);
    });

    it('opens the message page for nobody but community managers and super admins', function () {
        $message = ContactMessage::factory()->create();

        foreach ([adminUser(AdminRole::DeliveryManager), adminUser(AdminRole::FinanceOps), clientUser(), contributorUser()] as $user) {
            asUser($user)->get("/admin/contact-messages/{$message->id}")->assertForbidden();
        }

        asUser(adminUser(AdminRole::CommunityManager))->get("/admin/contact-messages/{$message->id}")->assertOk();
    });
});

describe('copy rules', function () {
    it('flags em dashes and AI wording but not ordinary words', function (string $text, bool $bad) {
        expect(PublicCopy::violation($text) !== null)->toBe($bad);
    })->with([
        ['Fast, calm and clear.', false],
        ["Fast \u{2014} calm", true],
        ['<p>Fast &mdash; calm</p>', true],
        ['Built with AI', true],
        ['We use artificial intelligence', true],
        ['Maintain the mail and the train', false],
        ['Said and paid', false],
    ]);
});
