<?php

use App\Enums\FaqAudience;
use App\Enums\FeaturedItemType;
use App\Enums\FeatureFlag;
use App\Enums\OnboardingState;
use App\Enums\ProjectStatus;
use App\Enums\ShowcaseConsent;
use App\Enums\UserStatus;
use App\Models\Category;
use App\Models\CmsSection;
use App\Models\Faq;
use App\Models\FeaturedItem;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\ProofOfWork;
use App\Models\Skill;
use App\Models\Testimonial;
use App\Models\User;
use App\Rules\PublicCopy;
use App\Services\FeatureFlags\FeatureFlags;
use App\Services\Website\HomepageBuilder;
use App\Support\Website\HomepageSections;
use Database\Seeders\DemoSeeder;
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
            ->assertSee('<span class="hero-word">Where</span> <span class="hero-word">Work</span> <span class="hero-word">Finds</span> <span class="hero-word text-brand-green">Talent</span>', false)
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
        foreach (range(1, 5) as $i) {
            publicContributor("Filler{$i} Person", ['slug' => "filler{$i}-person-abc123"]); // the community row needs 6 people
        }
        $org = Organization::factory()->verified()->create(['name' => 'Kestrel Foods', 'is_public' => true]);
        $privateOrg = Organization::factory()->verified()->create(['name' => 'Secret Holdings', 'is_public' => false]);
        $pendingOrg = Organization::factory()->create(['name' => 'Pending Partners', 'is_public' => true, 'status' => 'pending']);
        feature(FeaturedItemType::Contributor, $public->id, 1);
        feature(FeaturedItemType::Contributor, $private->id, 2);
        foreach ([$org, $privateOrg, $pendingOrg] as $i => $organization) {
            feature(FeaturedItemType::Organization, $organization->id, $i + 1);
        }

        $this->get('/')->assertOk()->assertSee('Asha')->assertSee('Kestrel Foods')
            ->assertDontSee('Hidden')->assertDontSee('Secret Holdings')->assertDontSee('Pending Partners');

        setFlag(FeatureFlag::PublicDirectories, false);
        $this->get('/')->assertDontSee('Asha')->assertDontSee('Kestrel Foods');
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

/** Public contributors, so the community row can show (it needs 6). */
function communityPeople(int $count, string $prefix = 'Person'): void
{
    foreach (range(1, $count) as $i) {
        publicContributor("{$prefix}{$i} Surname", ['slug' => str("{$prefix}{$i}")->lower()->toString().'-slug']);
    }
}

describe('community row', function () {
    it('hides the whole section under 6 people and shows it from 6', function () {
        seedSections();
        communityPeople(5);
        $this->get('/')->assertOk()->assertDontSee('See all contributors')->assertDontSee('class="marquee"', false);

        communityPeople(1, 'Sixth');
        app(HomepageBuilder::class)->flush();
        $this->get('/')->assertSee('See all contributors')->assertSee('data-cards="6"', false);
    });

    it('shows at most 12 cards, once visible and once as a hidden copy', function () {
        seedSections();
        communityPeople(14);

        $html = $this->get('/')->getContent();

        expect($html)->toContain('data-cards="12"')
            ->and(substr_count($html, 'href="'.url('/contributors').'/'))->toBe(24)
            ->and($html)->toContain('aria-hidden="true" inert');
    });

    it('never shows a private, pending or suspended contributor, or a profile with no slug', function () {
        seedSections();
        communityPeople(6);
        publicContributor('Private Priya', ['is_public' => false, 'slug' => 'private-priya-slug']);
        contributorIn(OnboardingState::PendingReview, ['is_public' => true, 'slug' => 'pending-pooja-slug'], ['name' => 'Pending Pooja']);
        contributorIn(OnboardingState::Suspended, ['is_public' => true, 'slug' => 'suspended-sana-slug'], ['name' => 'Suspended Sana']);
        contributorIn(OnboardingState::Active, ['is_public' => true, 'slug' => null], ['name' => 'Slugless Sam']);

        $this->get('/')->assertOk()->assertSee('Person1')
            ->assertDontSee('Private')->assertDontSee('Pending')->assertDontSee('Suspended')->assertDontSee('Slugless');
    });

    it('never shows a suspended user whose profile is still marked public and active', function () {
        seedSections();
        communityPeople(6);
        $user = publicContributor('Locked Out', ['slug' => 'locked-out-slug']);
        $user->update(['status' => UserStatus::Suspended]);
        app(HomepageBuilder::class)->flush();

        $this->get('/')->assertOk()->assertDontSee('Locked');
    });

    it('labels demo contributors outside production and never shows them in production', function () {
        seedSections();
        communityPeople(6);
        foreach (['Demo Dev', 'Demo Dia'] as $name) {
            publicContributor($name, ['slug' => str($name)->slug().'-demo'])->update(['email' => str($name)->slug().'@'.config('groibe.demo.email_domain')]);
        }
        app(HomepageBuilder::class)->flush();

        $this->get('/')->assertSee('Demo profile')->assertSee('>Demo<', false);

        app()->detectEnvironment(fn () => 'production');
        app(HomepageBuilder::class)->flush();

        $this->get('/')->assertOk()->assertDontSee('Demo profile')->assertDontSee('>Demo<', false)->assertSee('Person1');
    });

    it('matches the email domain DemoSeeder uses', function () {
        expect(config('groibe.demo.email_domain'))->toBe(DemoSeeder::EMAIL_DOMAIN);
    });

    it('shows a first name, a headline, at most three skills, and a photo only when there is one', function () {
        seedSections();
        communityPeople(5);
        $star = publicContributor('Dr Asha Rao', ['slug' => 'asha-rao-slug', 'headline' => 'Market researcher', 'photo_path' => 'profile-photos/asha.jpg']);
        foreach (Skill::factory()->count(4)->create() as $skill) {
            $star->skills()->attach($skill->id, ['level' => 'expert']);
        }

        $html = $this->get('/')->getContent();

        expect($html)->toContain('>Asha<')->not->toContain('Rao')
            ->and($html)->toContain('Market researcher')
            ->and($html)->toContain(route('contributors.photo', 'asha-rao-slug'))
            ->and($html)->not->toContain(route('contributors.photo', 'person1-slug'));

        $skills = preg_match('/asha-rao-slug".*?<\/li>/s', $html, $m) ? substr_count($m[0], 'rounded bg-zinc-100') : -1;
        expect($skills)->toBe(3);
    });

    it('keeps emails and phone numbers out of the cached copy', function () {
        seedSections();
        communityPeople(6);
        $this->get('/')->assertOk();

        $cached = json_encode(cache()->get(HomepageBuilder::CACHE_KEY));

        expect($cached)->toContain('person1-slug')->not->toContain('@')->not->toContain('phone');
    });

    it('is cleared when a profile changes', function () {
        seedSections();
        communityPeople(6);
        $this->get('/')->assertOk();

        $user = User::query()->where('name', 'Person1 Surname')->first();
        $user->profile->update(['headline' => 'A brand new headline']);

        $this->get('/')->assertSee('A brand new headline');
    });

    it('puts Admin picks first, then the newest others', function () {
        seedSections();
        communityPeople(7);
        feature(FeaturedItemType::Contributor, User::query()->where('name', 'Person1 Surname')->value('id'), 1);

        $html = $this->get('/')->getContent();

        expect(strpos($html, '>Person1<'))->toBeLessThan(strpos($html, '>Person7<'));
    });

    it('is hidden with the directory flag off', function () {
        seedSections();
        communityPeople(6);
        setFlag(FeatureFlag::PublicDirectories, false);

        $this->get('/')->assertOk()->assertDontSee('data-cards', false);
    });
});

describe('motion', function () {
    it('keeps the slogan as plain text in one H1', function () {
        seedSections();
        $html = $this->get('/')->getContent();

        expect(substr_count($html, '<h1'))->toBe(1)
            ->and(preg_match('/<h1.*?<\/h1>/s', $html, $h1))->toBe(1)
            ->and(trim(preg_replace('/\s+/', ' ', strip_tags($h1[0]))))->toBe('Where Ideas Find People')
            ->and($h1[0])->toContain('hero-word');
    });

    it('rotates real skill categories, hidden from screen readers, with one fixed sentence', function () {
        seedSections();
        foreach (['Design' => 1, 'Research' => 2, 'Finance' => 3] as $name => $sort) {
            $category = Category::factory()->create(['name' => $name, 'slug' => str($name)->slug(), 'sort' => $sort]);
            Skill::factory()->create(['category_id' => $category->id, 'is_active' => true]);
        }
        Category::factory()->create(['name' => 'Empty Category', 'sort' => 4]);
        $inactive = Category::factory()->create(['name' => 'Inactive Category', 'sort' => 5]);
        Skill::factory()->create(['category_id' => $inactive->id, 'is_active' => false]);
        app(HomepageBuilder::class)->flush();

        $html = $this->get('/')->getContent();

        expect($html)->toContain('<p class="sr-only">Where ideas find talent in Design, Research and Finance.</p>')
            ->and($html)->toContain('aria-hidden="true" class="text-xl font-semibold text-white">Where ideas find talent in')
            ->and($html)->toContain('data-count="3"')
            ->and($html)->toContain('<span>Design</span><span>Research</span><span>Finance</span>')
            ->and($html)->not->toContain('Empty Category')->not->toContain('Inactive Category');
    });

    it('leaves the rotating line out with fewer than two categories', function () {
        seedSections();
        $category = Category::factory()->create(['name' => 'Only One', 'sort' => 1]);
        Skill::factory()->create(['category_id' => $category->id, 'is_active' => true]);
        app(HomepageBuilder::class)->flush();

        $this->get('/')->assertOk()->assertDontSee('Where ideas find talent in')->assertDontSee('hero-rotor', false);
    });

    it('ships reduced-motion markup: a pause control, a scrollable row, and motion only when allowed', function () {
        seedSections();
        communityPeople(6);
        $html = $this->get('/')->getContent();

        expect($html)->toContain('motion-toggle')->toContain('Pause motion')
            ->and($html)->toContain('class="marquee"')->toContain('role="region" aria-label="Contributors"')
            ->and($html)->toContain('class="reveal"');

        $css = file_get_contents(resource_path('css/app.css'));
        $plain = strpos($css, '.marquee { overflow-x: auto; }');
        $motion = strpos($css, '@media (prefers-reduced-motion: no-preference)');

        expect($plain)->not->toBeFalse()->and($motion)->not->toBeFalse()->and($plain)->toBeLessThan($motion)
            ->and(strpos($css, 'animation: marquee-left'))->toBeGreaterThan($motion)
            ->and(strpos($css, 'animation: hero-word-in'))->toBeGreaterThan($motion)
            ->and(strpos($css, 'animation: reveal-up'))->toBeGreaterThan($motion)
            ->and($css)->toContain('animation-play-state: paused')->toContain('.marquee:focus-within')->toContain('.marquee:hover');
    });

    it('moves no faster than a card every 7 seconds and adds no script, style attribute or Alpine', function () {
        seedSections();
        communityPeople(6);
        $html = $this->get('/')->getContent();
        $css = file_get_contents(resource_path('css/app.css'));

        // 272 px (card plus gap) per 7 s is about 39 px a second, for every card count.
        foreach (range(6, 12) as $n) {
            expect($css)->toContain("data-cards='{$n}'] { --marquee-duration: ".($n * 7).'s; }');
        }

        expect($html)->not->toMatch('/\sstyle=/i')->and($html)->not->toContain('x-data')->not->toContain('alpine')->not->toContain('wire:');
    });
});
