<?php

use App\Enums\AdminRole;
use App\Enums\AgreementType;
use App\Enums\FeaturedItemType;
use App\Enums\ProjectStatus;
use App\Enums\ShowcaseConsent;
use App\Filament\Admin\Resources\ContactMessages\Pages\ListContactMessages;
use App\Filament\Admin\Resources\Faqs\Pages\ListFaqs;
use App\Filament\Admin\Resources\FeaturedItems\Pages\ListFeaturedItems;
use App\Filament\Admin\Resources\HomepageSections\Pages\CreateHomepageSection;
use App\Filament\Admin\Resources\HomepageSections\Pages\EditHomepageSection;
use App\Filament\Admin\Resources\HomepageSections\Pages\ListHomepageSections;
use App\Filament\Admin\Resources\Pages\Pages\CreatePage;
use App\Filament\Admin\Resources\Pages\Pages\ListPages;
use App\Filament\Admin\Resources\Testimonials\Pages\ListTestimonials;
use App\Models\AgreementTemplate;
use App\Models\CmsSection;
use App\Models\ContactMessage;
use App\Models\Faq;
use App\Models\FeaturedItem;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\Page;
use App\Models\ProofOfWork;
use App\Models\Skill;
use App\Models\Testimonial;
use Filament\Forms\Components\RichEditor;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** How many queries a callback runs. */
function auditQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** Runs $page for 3 rows and again after adding 7 more, and returns [small, large]. @param Closure(int): void $addRows */
function auditGrowth(Closure $page, Closure $addRows): array
{
    $addRows(3);
    $page();
    $small = auditQueries($page);
    $addRows(7);
    $page(); // new rows clear the public caches, so warm them again: only row-driven queries are compared
    $large = auditQueries($page);

    return [$small, $large];
}

describe('queries do not grow with the rows', function () {
    it('on the organizations list and the FAQ page', function () {
        $n = 0;
        $addRows = function (int $count) use (&$n): void {
            foreach (range(1, $count) as $ignored) {
                $n++;
                Organization::factory()->verified()->create(['slug' => "org-{$n}", 'is_public' => true]);
                Faq::factory()->create();
            }
        };

        foreach ([route('organizations.index'), route('faq')] as $url) {
            $n = 0;
            DB::table('organizations')->delete();
            DB::table('faqs')->delete();
            [$small, $large] = auditGrowth(fn () => $this->get($url)->assertOk(), $addRows);

            expect($large)->toBe($small, $url);
        }
    });

    it('on a skill page that lists roles, contributors and proof of work', function () {
        $skill = Skill::factory()->create(['slug' => 'research']);
        $n = 0;
        $addRows = function (int $count) use ($skill, &$n): void {
            foreach (range(1, $count) as $ignored) {
                $n++;
                OpenRole::factory()->published()->public()->create()->skills()->attach($skill->id);
                publicContributor("Person {$n}", ['slug' => "person-{$n}"])->skills()->attach($skill->id, ['level' => 'expert']);
                $seat = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::Anonymous]);
                ProofOfWork::factory()->published()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id])->skills()->attach($skill->id);
            }
        };

        [$small, $large] = auditGrowth(fn () => $this->get(route('skills.show', 'research'))->assertOk(), $addRows);

        expect($large)->toBe($small);
    });

    it('on every new admin list', function () {
        $admin = adminUser(AdminRole::CommunityManager);
        $n = 0;
        $addRows = function (int $count) use (&$n): void {
            foreach (range(1, $count) as $ignored) {
                $n++;
                CmsSection::factory()->create();
                Testimonial::factory()->create();
                Faq::factory()->create();
                Page::factory()->create();
                ContactMessage::factory()->create(['handled_by' => adminUser()->id]);
                $role = OpenRole::factory()->published()->public()->create();
                FeaturedItem::factory()->of(FeaturedItemType::OpenRole, $role->id)->create();
            }
        };
        $lists = [ListHomepageSections::class, ListTestimonials::class, ListFaqs::class, ListPages::class, ListContactMessages::class, ListFeaturedItems::class];

        $addRows(3);
        foreach ($lists as $list) {
            Livewire::actingAs($admin)->test($list); // warm permissions and sessions
        }
        $small = collect($lists)->map(fn ($list) => auditQueries(fn () => Livewire::actingAs($admin)->test($list)->assertOk()))->all();

        $addRows(7);
        $large = collect($lists)->map(fn ($list) => auditQueries(fn () => Livewire::actingAs($admin)->test($list)->assertOk()))->all();

        expect($large)->toBe($small);
    });
});

describe('denied access', function () {
    it('keeps every role but community manager and super admin off the create and edit screens', function () {
        $records = [
            'homepage-sections' => CmsSection::factory()->create(),
            'testimonials' => Testimonial::factory()->create(),
            'faqs' => Faq::factory()->create(),
            'pages' => Page::factory()->create(),
            'featured-items' => FeaturedItem::factory()->create(),
        ];

        foreach ([adminUser(AdminRole::DeliveryManager), adminUser(AdminRole::FinanceOps), clientUser(), contributorUser()] as $user) {
            foreach ($records as $resource => $record) {
                asUser($user)->get("/admin/{$resource}/create")->assertForbidden();
                asUser($user)->get("/admin/{$resource}/{$record->getKey()}/edit")->assertForbidden();
            }
        }

        foreach ($records as $resource => $record) {
            asUser(adminUser(AdminRole::CommunityManager))->get("/admin/{$resource}/{$record->getKey()}/edit")->assertOk();
        }
    });

    it('has no create or edit screen for contact messages', function () {
        $message = ContactMessage::factory()->create();

        asUser(adminUser())->get('/admin/contact-messages/create')->assertNotFound();
        asUser(adminUser())->get("/admin/contact-messages/{$message->id}/edit")->assertNotFound();
    });
});

describe('needs review on every kind of content', function () {
    it('lets an admin approve a FAQ and a testimonial from the list, and only then switch them on', function () {
        $admin = adminUser(AdminRole::CommunityManager);
        $faq = Faq::factory()->needsReview()->create();

        Livewire::actingAs($admin)->test(ListFaqs::class)->assertTableActionVisible('approveWording', $faq)->callTableAction('approveWording', $faq);

        expect($faq->fresh()->needs_review)->toBeFalse()->and($faq->fresh()->is_active)->toBeFalse();

        Livewire::actingAs($admin)->test(ListFaqs::class)->assertTableActionHidden('approveWording', $faq);

        $testimonial = Testimonial::factory()->needsReview()->create();
        Livewire::actingAs($admin)->test(ListTestimonials::class)->callTableAction('approveWording', $testimonial);

        expect($testimonial->fresh()->needs_review)->toBeFalse();
    });
});

describe('Pass 8 hardening', function () {
    it('serves robots.txt from the application, not from a static file that would hide the rules', function () {
        expect(file_exists(public_path('robots.txt')))->toBeFalse();

        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /admin')->assertSee('Sitemap: ');
    });

    it('answers a HEAD request for a CMS page the same way as a GET', function () {
        Page::factory()->create(['slug' => 'careers']);
        Page::factory()->inactive()->create(['slug' => 'old-page']);

        $this->call('HEAD', '/careers')->assertOk();
        $this->call('HEAD', '/old-page')->assertNotFound();
        $this->call('POST', '/careers')->assertStatus(405);
    });

    it('trusts a forwarded address only when proxies are trusted, so the contact throttle sees the visitor', function () {
        config(['groibe.contact_email' => null]);
        $post = fn () => $this->withHeader('X-Forwarded-For', '203.0.113.9')
            ->post(route('contact.store'), ['name' => 'Asha Rao', 'email' => 'asha@example.test', 'message' => 'I would like to know more.']);

        $post();
        expect(ContactMessage::query()->latest('id')->value('ip'))->toBe('127.0.0.1');

        TrustProxies::at('*');

        try {
            $post();
            expect(ContactMessage::query()->latest('id')->value('ip'))->toBe('203.0.113.9');
        } finally {
            TrustProxies::flushState();
        }

        expect(array_key_exists('trusted_proxies', config('groibe')))->toBeTrue()
            ->and(file_get_contents(app_path('Providers/AppServiceProvider.php')))->toContain('TrustProxies::at(')
            ->and(file_get_contents(base_path('.env.example')))->toContain('TRUSTED_PROXIES=');
    });

    it('offers no file attachments in the page editor', function () {
        Livewire::actingAs(adminUser(AdminRole::CommunityManager))->test(CreatePage::class)
            ->assertFormComponentExists('body_html', fn (RichEditor $editor): bool => ! $editor->hasToolbarButton('attachFiles'));
    });

    it('refuses a protocol-relative link in a homepage section', function (string $url, bool $ok) {
        $section = CmsSection::factory()->create(['key' => 'hero', 'content' => []]);
        $test = Livewire::actingAs(adminUser(AdminRole::CommunityManager))->test(EditHomepageSection::class, ['record' => $section->getKey()])
            ->fillForm(['content.primary_url' => $url])->call('save');

        $ok ? $test->assertHasNoFormErrors() : $test->assertHasFormErrors(['content.primary_url']);
    })->with([['/opportunities', true], ['https://example.test/x', true], ['//evil.test/x', false], ['http://example.test', false], ['javascript:alert(1)', false]]);

    it('keeps a hidden Terms template off the sitemap and the menu', function () {
        AgreementTemplate::factory()->ofType(AgreementType::Terms)->inactive()->create(['body_html' => '<p>Real.</p>']);

        $this->get(route('terms'))->assertNotFound();
        expect($this->get(route('sitemap'))->getContent())->not->toContain(route('terms'));
    });

    it('lets only a community manager or super admin create a homepage section', function () {
        Livewire::actingAs(adminUser(AdminRole::CommunityManager))->test(CreateHomepageSection::class)->assertOk();
        asUser(adminUser(AdminRole::DeliveryManager))->get('/admin/homepage-sections/create')->assertForbidden();
    });
});
