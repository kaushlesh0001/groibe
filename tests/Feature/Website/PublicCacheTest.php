<?php

use App\Enums\AgreementType;
use App\Enums\FeaturedItemType;
use App\Enums\FeatureFlag;
use App\Enums\ProjectStatus;
use App\Enums\ShowcaseConsent;
use App\Enums\UserStatus;
use App\Models\AgreementTemplate;
use App\Models\CmsSection;
use App\Models\Faq;
use App\Models\FeaturedItem;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\Page;
use App\Models\ProofOfWork;
use App\Models\Skill;
use App\Models\Testimonial;
use App\Services\FeatureFlags\FeatureFlags;
use App\Services\Website\HomepageBuilder;
use App\Services\Website\PublicCache;
use App\Services\Website\PublicNavigation;
use App\Support\Website\HomepageSections;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** One active section per key, so the homepage shows everything it has data for. */
function cachedHome(): void
{
    foreach (array_keys(HomepageSections::TITLES) as $i => $key) {
        CmsSection::factory()->create(['key' => $key, 'title' => HomepageSections::title($key), 'sort' => $i + 1, 'content' => []]);
    }
}

/** The SQL of every query a request runs. @return list<string> */
function requestSql(string $url): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get($url)->assertOk();
    $sql = collect(DB::getQueryLog())->pluck('query')->all();
    DB::disableQueryLog();

    return $sql;
}

function pick(FeaturedItemType $type, int $id): void
{
    FeaturedItem::factory()->of($type, $id)->create();
}

describe('lifetime', function () {
    it('keeps public entries between 5 and 15 minutes whatever the setting says', function (int $setting, int $expected) {
        config(['groibe.cache.public_minutes' => $setting]);

        expect(PublicCache::minutes())->toBe($expected);
    })->with([[0, 5], [1, 5], [5, 5], [10, 10], [15, 15], [60, 15]]);

    it('serves the homepage and the sitemap from the cache on the second request', function () {
        cachedHome();
        OpenRole::factory()->published()->public()->create(['title' => 'Survey analyst']);

        $this->get('/')->assertOk();
        $this->get(route('sitemap'))->assertOk();

        expect(Cache::has(HomepageBuilder::CACHE_KEY))->toBeTrue()->and(Cache::has(PublicNavigation::CACHE_KEY))->toBeTrue()->and(Cache::has(PublicCache::SITEMAP_KEY))->toBeTrue();

        $sitemap = implode(' ', requestSql(route('sitemap')));
        $home = implode(' ', requestSql('/'));

        expect($sitemap)->not->toContain('"open_roles"')->not->toContain('"profiles"')->not->toContain('"organizations"')->not->toContain('"pages"')->not->toContain('"skills"')
            ->and($home)->not->toContain('"cms_sections"')->not->toContain('"open_roles"')->not->toContain('"faqs"')->not->toContain('"testimonials"');
    });
});

describe('cleared when the data changes', function () {
    it('shows a new section wording at once', function () {
        cachedHome();
        $this->get('/')->assertSee('Ready to start?');

        CmsSection::query()->where('key', 'final_cta')->first()->update(['content' => ['heading' => 'Start today']]);

        $this->get('/')->assertSee('Start today')->assertDontSee('Ready to start?');

        CmsSection::query()->where('key', 'final_cta')->first()->update(['is_active' => false]);

        $this->get('/')->assertDontSee('Start today');
    });

    it('shows a newly featured role and drops it when it is closed, unpublished or the pick is removed', function () {
        cachedHome();
        $role = OpenRole::factory()->published()->public()->create(['title' => 'Survey analyst']);
        $this->get('/')->assertDontSee('Survey analyst');

        $pick = FeaturedItem::factory()->of(FeaturedItemType::OpenRole, $role->id)->create();
        $this->get('/')->assertSee('Survey analyst');

        $role->update(['status' => 'closed']);
        $this->get('/')->assertDontSee('Survey analyst');

        $role->update(['status' => 'published']);
        $this->get('/')->assertSee('Survey analyst');

        $pick->delete();
        $this->get('/')->assertDontSee('Survey analyst');
    });

    it('drops an organization the moment it is made private and updates the stats', function () {
        cachedHome();
        $org = Organization::factory()->verified()->create(['name' => 'Kestrel Foods', 'is_public' => true, 'is_featured' => true]);
        $text = fn () => preg_replace('/\s+/', ' ', strip_tags($this->get('/')->getContent()));

        expect($text())->toContain('Kestrel Foods')->toContain('Organizations 1');

        $org->update(['is_public' => false]);

        expect($text())->not->toContain('Kestrel Foods')->toContain('Organizations 1');

        $org->update(['status' => 'suspended']);

        expect($text())->not->toContain('Organizations 1');
    });

    it('drops a contributor the moment they are suspended or their profile is made private', function () {
        cachedHome();
        $person = publicContributor('Asha Rao');
        pick(FeaturedItemType::Contributor, $person->id);
        $this->get('/')->assertSee('Asha Rao');

        $person->profile->update(['is_public' => false]);
        $this->get('/')->assertDontSee('Asha Rao');

        $person->profile->update(['is_public' => true]);
        $this->get('/')->assertSee('Asha Rao');

        $person->update(['status' => UserStatus::Suspended]);
        $this->get('/')->assertDontSee('Asha Rao');
    });

    it('drops proof of work when it is hidden or the client withdraws consent', function () {
        cachedHome();
        $seat = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::Anonymous]);
        $proof = ProofOfWork::factory()->published()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id, 'title' => 'Regional market sizing']);
        pick(FeaturedItemType::ProofOfWork, $proof->id);

        $this->get('/')->assertSee('Regional market sizing');

        $seat['project']->update(['showcase_consent' => ShowcaseConsent::None]);
        $this->get('/')->assertDontSee('Regional market sizing');

        $seat['project']->update(['showcase_consent' => ShowcaseConsent::Named]);
        $this->get('/')->assertSee('Regional market sizing');

        $proof->update(['status' => 'hidden']);
        $this->get('/')->assertDontSee('Regional market sizing');
    });

    it('shows testimonials and FAQs as soon as they are approved', function () {
        cachedHome();
        $this->get('/')->assertDontSee('Delivered on time.');

        $quote = Testimonial::factory()->needsReview()->create(['quote' => 'Delivered on time.']);
        $faq = Faq::factory()->needsReview()->create(['question' => 'How do I start?']);
        $this->get('/')->assertDontSee('Delivered on time.')->assertDontSee('How do I start?');

        $quote->update(['needs_review' => false, 'is_active' => true]);
        $faq->update(['needs_review' => false, 'is_active' => true]);

        $this->get('/')->assertSee('Delivered on time.')->assertSee('How do I start?');
    });

    it('updates the menu and the sitemap when About, Terms and Privacy are published', function () {
        $footer = function (): string {
            $html = $this->get('/')->getContent();

            return substr($html, strpos($html, '<footer'));
        };
        $sitemap = fn (): string => $this->get(route('sitemap'))->getContent();

        expect($footer())->not->toContain('>About<')->not->toContain('>Terms<');
        expect($sitemap())->not->toContain(route('about'));

        Page::factory()->create(['slug' => 'about']);
        $terms = AgreementTemplate::factory()->ofType(AgreementType::Terms)->create(['body_html' => '<p>Real terms.</p>', 'is_active' => true]);

        expect($footer())->toContain('>About<')->toContain('>Terms<');
        expect($sitemap())->toContain(route('about'))->toContain(route('terms'));

        $terms->update(['is_active' => false]);

        expect($footer())->not->toContain('>Terms<');
        expect($sitemap())->not->toContain(route('terms'));
    });

    it('updates the menu and the homepage when a feature flag is switched', function () {
        cachedHome();
        $role = OpenRole::factory()->published()->public()->create(['title' => 'Survey analyst']);
        pick(FeaturedItemType::OpenRole, $role->id);
        $this->get('/')->assertSee('Survey analyst')->assertSee('Explore Opportunities');

        app(FeatureFlags::class)->set(FeatureFlag::OpenRoles, false);
        app(FeatureFlags::class)->flush();

        $this->get('/')->assertDontSee('Survey analyst')->assertDontSee('Explore Opportunities');
    });

    it('drops a person, an organization and a skill from the sitemap when they stop being public', function () {
        $person = publicContributor('Asha Rao', ['slug' => 'asha-rao']);
        $org = Organization::factory()->verified()->create(['slug' => 'kestrel', 'is_public' => true]);
        $skill = Skill::factory()->create(['slug' => 'research']);
        OpenRole::factory()->published()->public()->create()->skills()->attach($skill->id);

        $sitemap = fn (): string => $this->get(route('sitemap'))->getContent();

        expect($sitemap())->toContain(route('contributors.show', 'asha-rao'))->toContain(route('organizations.show', 'kestrel'))->toContain(route('skills.show', 'research'));

        $person->profile->update(['is_public' => false]);
        $org->update(['is_public' => false]);
        $skill->update(['is_active' => false]);

        expect($sitemap())->not->toContain('asha-rao')->not->toContain('kestrel')->not->toContain('skills/research');
    });
});

describe('what is not worth clearing for', function () {
    it('keeps the cache when a user only signs in or a profile keeps private details', function () {
        cachedHome();
        $person = publicContributor('Asha Rao');
        $this->get('/')->assertOk();

        $person->update(['last_login_at' => now()]);
        $person->profile->update(['phone' => '9999999999', 'bio' => 'A private note.']);

        expect(Cache::has(HomepageBuilder::CACHE_KEY))->toBeTrue();

        $person->profile->update(['headline' => 'A new public headline']);

        expect(Cache::has(HomepageBuilder::CACHE_KEY))->toBeFalse();
    });
});

describe('what the cache holds', function () {
    it('keeps contact details, contract values and tax numbers out of the cached copy', function () {
        cachedHome();
        $seat = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::Named, 'contract_value' => 987654]);
        $seat['organization']->update(['gstin' => '29ABCDE1234F1Z5', 'is_public' => true, 'is_featured' => true, 'zoho_crm_id' => 'ZOHO-SECRET']);
        $seat['contributor']->profile->update(['phone' => '9000000001', 'bio' => 'Private biography text.']);
        $proof = ProofOfWork::factory()->published()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id, 'contribution_text' => 'Private working notes.']);
        pick(FeaturedItemType::ProofOfWork, $proof->id);
        pick(FeaturedItemType::Contributor, $seat['contributor']->id);
        pick(FeaturedItemType::Organization, $seat['organization']->id);

        $this->get('/')->assertOk()->assertSee($seat['contributor']->name);

        $stored = serialize(Cache::get(HomepageBuilder::CACHE_KEY));

        foreach ([$seat['contributor']->email, $seat['contributor']->password, '9000000001', 'Private biography text.', '987654', '29ABCDE1234F1Z5', 'ZOHO-SECRET', 'Private working notes.'] as $secret) {
            expect($stored)->not->toContain($secret);
        }

        expect($stored)->toContain($seat['contributor']->name);
    });
});
