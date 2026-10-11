<?php

use App\Enums\FaqAudience;
use App\Enums\FeaturedItemType;
use App\Enums\FeatureFlag;
use App\Enums\ProjectStatus;
use App\Enums\ShowcaseConsent;
use App\Models\CmsSection;
use App\Models\Faq;
use App\Models\FeaturedItem;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\ProofOfWork;
use App\Models\Testimonial;
use App\Rules\PublicCopy;
use App\Services\FeatureFlags\FeatureFlags;
use App\Services\Website\HomepageBuilder;
use App\Support\Website\HomepageSections;
use Illuminate\Support\Facades\DB;

/** Seeds one active section per key, in the spec's order, so a test can change only what it cares about. */
function seedSections(array $override = []): void
{
    foreach (array_keys(HomepageSections::TITLES) as $i => $key) {
        CmsSection::factory()->create(['key' => $key, 'title' => HomepageSections::title($key), 'sort' => $i + 1, 'content' => [], ...($override[$key] ?? [])]);
    }
}

function setFlag(FeatureFlag $flag, bool $on): void
{
    app(FeatureFlags::class)->set($flag, $on);
    app(FeatureFlags::class)->flush();
    app(HomepageBuilder::class)->flush();
}

function feature(FeaturedItemType $type, int $id, int $sort = 1): void
{
    FeaturedItem::factory()->of($type, $id)->create(['sort' => $sort]);
}

describe('empty database', function () {
    it('renders the built-in sections and leaves out everything that has no data', function () {
        $this->get('/')->assertOk()
            ->assertSee('Where Ideas Find')
            ->assertSee('One platform. Two sides. One delivery loop.')
            ->assertSee('How it works')
            ->assertSee('Ready to start?')
            ->assertDontSee('Open roles')
            ->assertDontSee('Proof of work')
            ->assertDontSee('Groibe in numbers')
            ->assertDontSee('What people say')
            ->assertDontSee('Questions');
    });

    it('shows the explore button only while open roles are on', function () {
        $this->get('/')->assertSee('Explore Opportunities');

        setFlag(FeatureFlag::OpenRoles, false);

        $this->get('/')->assertOk()->assertDontSee('Explore Opportunities');
    });
});

describe('sections from Admin', function () {
    it('shows only the sections that are switched on and keeps them in Admin order', function () {
        seedSections(['faq' => ['is_active' => false], 'ecosystem' => ['sort' => 30], 'how_it_works' => ['sort' => 20], 'final_cta' => ['sort' => 40]]);

        $html = $this->get('/')->assertOk()->getContent();

        expect(strpos($html, 'How it works'))->toBeLessThan(strpos($html, 'One platform. Two sides.'))
            ->and(strpos($html, 'One platform. Two sides.'))->toBeLessThan(strpos($html, 'Ready to start?'));

        CmsSection::query()->where('key', 'how_it_works')->update(['is_active' => false]);
        app(HomepageBuilder::class)->flush();

        $this->get('/')->assertDontSee('Five steps, from the first brief to proof of the work.');
    });

    it('uses Admin wording and falls back to the built-in text for empty fields', function () {
        seedSections(['hero' => ['content' => ['heading' => 'Where Work Finds', 'highlight' => 'Talent', 'subheading' => '']]]);

        $this->get('/')->assertOk()
            ->assertSee('Where Work Finds <span class="text-brand-green">Talent</span>', false)
            ->assertSee('Groibe assembles and manages teams of skilled professionals');
    });

    it('never shows a section that needs review', function () {
        seedSections(['final_cta' => ['is_active' => false, 'needs_review' => true, 'content' => ['heading' => 'Draft wording']]]);

        $this->get('/')->assertOk()->assertDontSee('Draft wording');
    });

    it('escapes anything an admin types into a section', function () {
        seedSections(['ecosystem' => ['content' => ['heading' => '<script>alert(1)</script>']]]);

        $this->get('/')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    });

    it('serves from the cache until it is cleared', function () {
        seedSections();
        $this->get('/')->assertSee('Ready to start?');

        CmsSection::query()->where('key', 'final_cta')->update(['content' => json_encode(['heading' => 'Changed heading'])]);

        $this->get('/')->assertSee('Ready to start?')->assertDontSee('Changed heading');

        app(HomepageBuilder::class)->flush();

        $this->get('/')->assertSee('Changed heading')->assertDontSee('Ready to start?');
    });
});

describe('live data sections', function () {
    it('shows featured open roles and leaves out roles the public cannot see', function () {
        seedSections();
        $live = OpenRole::factory()->published()->public()->create(['title' => 'Survey analyst']);
        $draft = OpenRole::factory()->create(['title' => 'Draft analyst']);
        $members = OpenRole::factory()->published()->create(['title' => 'Members only analyst']);
        feature(FeaturedItemType::OpenRole, $live->id, 1);
        feature(FeaturedItemType::OpenRole, $draft->id, 2);
        feature(FeaturedItemType::OpenRole, $members->id, 3);

        $this->get('/')->assertOk()->assertSee('Open roles')->assertSee('Survey analyst')->assertDontSee('Draft analyst')->assertDontSee('Members only analyst');
    });

    it('hides the roles section when open roles are off or nothing is featured', function () {
        seedSections();
        $role = OpenRole::factory()->published()->public()->create(['title' => 'Survey analyst']);

        $this->get('/')->assertDontSee('Survey analyst');

        feature(FeaturedItemType::OpenRole, $role->id);
        app(HomepageBuilder::class)->flush();
        $this->get('/')->assertSee('Survey analyst');

        setFlag(FeatureFlag::OpenRoles, false);
        $this->get('/')->assertDontSee('Survey analyst');
    });

    it('shows featured proof of work only when published, public and consented', function () {
        seedSections();
        $ok = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::Anonymous]);
        $none = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::None]);
        $shown = ProofOfWork::factory()->published()->create(['user_id' => $ok['contributor']->id, 'project_id' => $ok['project']->id, 'title' => 'Regional market sizing']);
        $blocked = ProofOfWork::factory()->published()->create(['user_id' => $none['contributor']->id, 'project_id' => $none['project']->id, 'title' => 'Consent none work']);
        feature(FeaturedItemType::ProofOfWork, $shown->id, 1);
        feature(FeaturedItemType::ProofOfWork, $blocked->id, 2);

        $this->get('/')->assertOk()->assertSee('Regional market sizing')->assertSee('Confidential client')->assertDontSee('Consent none work');

        setFlag(FeatureFlag::ProofOfWorkPublic, false);
        $this->get('/')->assertDontSee('Regional market sizing');
    });

    it('shows featured public contributors and organizations only, and hides both when directories are off', function () {
        seedSections();
        $public = publicContributor('Asha Rao');
        $private = publicContributor('Hidden Person', ['is_public' => false]);
        $org = Organization::factory()->verified()->create(['name' => 'Kestrel Foods', 'is_public' => true]);
        $privateOrg = Organization::factory()->verified()->create(['name' => 'Secret Holdings', 'is_public' => false]);
        $pendingOrg = Organization::factory()->create(['name' => 'Pending Partners', 'is_public' => true, 'status' => 'pending']);
        feature(FeaturedItemType::Contributor, $public->id, 1);
        feature(FeaturedItemType::Contributor, $private->id, 2);
        foreach ([$org, $privateOrg, $pendingOrg] as $i => $organization) {
            feature(FeaturedItemType::Organization, $organization->id, $i + 1);
        }

        $this->get('/')->assertOk()->assertSee('Asha Rao')->assertSee('Kestrel Foods')
            ->assertDontSee('Hidden Person')->assertDontSee('Secret Holdings')->assertDontSee('Pending Partners');

        setFlag(FeatureFlag::PublicDirectories, false);
        $this->get('/')->assertDontSee('Asha Rao')->assertDontSee('Kestrel Foods');
    });

    it('lists organizations flagged featured on the organization itself', function () {
        seedSections();
        Organization::factory()->verified()->create(['name' => 'Flagged Foods', 'is_public' => true, 'is_featured' => true]);
        Organization::factory()->verified()->create(['name' => 'Quiet Foods', 'is_public' => true, 'is_featured' => false]);

        $this->get('/')->assertSee('Flagged Foods')->assertDontSee('Quiet Foods');
    });

    it('shows active testimonials, never one that needs review, and prefers Admin picks', function () {
        seedSections();
        Testimonial::factory()->create(['author_name' => 'Real Client', 'quote' => 'Delivered on time.']);
        Testimonial::factory()->needsReview()->create(['author_name' => 'Draft Person', 'quote' => 'Draft quote.']);
        Testimonial::factory()->inactive()->create(['author_name' => 'Off Person', 'quote' => 'Off quote.']);

        $this->get('/')->assertOk()->assertSee('Delivered on time.')->assertDontSee('Draft quote.')->assertDontSee('Off quote.');

        $picked = Testimonial::factory()->create(['author_name' => 'Picked Person', 'quote' => 'Picked quote.']);
        feature(FeaturedItemType::Testimonial, $picked->id);
        app(HomepageBuilder::class)->flush();

        $this->get('/')->assertSee('Picked quote.')->assertDontSee('Delivered on time.');
    });

    it('shows FAQ tabs only for audiences that have questions and never a flagged question', function () {
        seedSections();
        Faq::factory()->audience(FaqAudience::Client)->create(['question' => 'How do I start a project?']);
        Faq::factory()->audience(FaqAudience::Contributor)->needsReview()->create(['question' => 'Draft question?']);

        $this->get('/')->assertOk()->assertSee('How do I start a project?')->assertSee('Clients')
            ->assertDontSee('Draft question?')->assertDontSee('>Contributors</label>', false)->assertDontSee('>General</label>', false);
    });
});

describe('stats', function () {
    it('shows live counts, replaces one with an override and leaves out a zero', function () {
        seedSections(['stats' => ['content' => ['override_projects' => 12]]]);
        publicContributor('Asha Rao');
        publicContributor('Ben Roy');
        Organization::factory()->verified()->count(3)->create();
        Organization::factory()->create(['status' => 'pending']);

        $html = $this->get('/')->assertOk()->assertSee('Groibe in numbers')->getContent();
        $text = preg_replace('/\s+/', ' ', strip_tags($html));

        expect($text)->toContain('Contributors 2')->toContain('Organizations 3')->toContain('Projects delivered 12');
    });

    it('counts delivered projects as accepted or closed', function () {
        seedSections();
        projectWithCompletedSeat(ProjectStatus::Accepted);
        projectWithCompletedSeat(ProjectStatus::Closed);
        projectWithCompletedSeat(ProjectStatus::Active);

        $text = preg_replace('/\s+/', ' ', strip_tags($this->get('/')->getContent()));

        expect($text)->toContain('Projects delivered 2');
    });

    it('hides the whole section when every count is zero', function () {
        seedSections();

        $this->get('/')->assertDontSee('Groibe in numbers');
    });
});

describe('quality', function () {
    it('runs the same number of queries for 3 featured items as for 10', function () {
        seedSections();
        config(['groibe.cache.public_minutes' => 0]);
        $count = function (): int {
            app(HomepageBuilder::class)->flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $addRoles = function (int $n): void {
            OpenRole::factory()->count($n)->published()->public()->create()->each(fn (OpenRole $role) => feature(FeaturedItemType::OpenRole, $role->id, $role->id));
            foreach (range(1, $n) as $i) {
                $profile = publicContributor('Person '.str()->random(5), ['slug' => 'person-'.str()->lower(str()->random(8))]);
                feature(FeaturedItemType::Contributor, $profile->id, $i);
            }
        };

        $addRoles(3);
        $count();
        $small = $count();
        $addRoles(7);
        $count(); // new rows clear the menu cache, so warm it again: only row-driven queries are compared

        expect($count())->toBe($small);
    });

    it('keeps em dashes and AI wording out of the built-in homepage text', function () {
        seedSections();
        $text = html_entity_decode(strip_tags($this->get('/')->getContent()));

        expect(PublicCopy::violation($text))->toBeNull();
    });
});
