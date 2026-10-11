<?php

use App\Enums\AgreementType;
use App\Enums\FeatureFlag;
use App\Enums\ProjectStatus;
use App\Enums\ShowcaseConsent;
use App\Models\AgreementTemplate;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\Page;
use App\Models\ProofOfWork;
use App\Models\Skill;
use App\Services\FeatureFlags\FeatureFlags;
use App\Services\Website\HomepageBuilder;
use App\Services\Website\PublicNavigation;
use Database\Seeders\AgreementTemplateSeeder;

function sitemapUrls(): array
{
    $xml = test()->get(route('sitemap'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
    preg_match_all('#<loc>(.*?)</loc>#', $xml, $m);

    return array_map('html_entity_decode', $m[1]);
}

describe('sitemap', function () {
    it('lists the built-in pages and leaves out About, Terms and Privacy until they are published', function () {
        $urls = sitemapUrls();

        expect($urls)->toContain(route('home'), route('how-it-works'), route('for-contributors'), route('for-clients'), route('faq'), route('contact'))
            ->and($urls)->not->toContain(route('about'), route('terms'), route('privacy'));

        $this->seed(AgreementTemplateSeeder::class);

        expect(sitemapUrls())->not->toContain(route('terms'), route('privacy'));
    });

    it('lists About, Terms, Privacy and CMS pages once published, and never a page that is off or needs review', function () {
        Page::factory()->create(['slug' => 'about']);
        Page::factory()->create(['slug' => 'careers']);
        Page::factory()->inactive()->create(['slug' => 'old-page']);
        Page::factory()->needsReview()->create(['slug' => 'draft-page']);
        AgreementTemplate::factory()->ofType(AgreementType::Terms)->create(['body_html' => '<p>Real terms.</p>', 'is_active' => true]);

        $urls = sitemapUrls();

        expect($urls)->toContain(route('about'), url('careers'), route('terms'))
            ->and($urls)->not->toContain(url('old-page'), url('draft-page'), route('privacy'));
        expect(count(array_keys($urls, route('about'), true)))->toBe(1);
    });

    it('lists only verified, public organizations', function () {
        $public = Organization::factory()->verified()->create(['slug' => 'public-org', 'is_public' => true]);
        $private = Organization::factory()->verified()->create(['slug' => 'private-org', 'is_public' => false]);
        $pending = Organization::factory()->create(['slug' => 'pending-org', 'is_public' => true, 'status' => 'pending']);

        expect(sitemapUrls())->toContain(route('organizations.index'), route('organizations.show', $public->slug))
            ->and(sitemapUrls())->not->toContain(route('organizations.show', $private->slug), route('organizations.show', $pending->slug));
    });

    it('lists only skills that have something public to show', function () {
        $used = Skill::factory()->create(['slug' => 'used-skill']);
        $empty = Skill::factory()->create(['slug' => 'empty-skill']);
        $onDraft = Skill::factory()->create(['slug' => 'draft-only-skill']);
        $inactive = Skill::factory()->create(['slug' => 'inactive-skill', 'is_active' => false]);
        OpenRole::factory()->published()->public()->create()->skills()->attach([$used->id, $inactive->id]);
        OpenRole::factory()->create()->skills()->attach($onDraft->id);

        expect(sitemapUrls())->toContain(route('skills.show', 'used-skill'))
            ->and(sitemapUrls())->not->toContain(route('skills.show', 'empty-skill'), route('skills.show', 'draft-only-skill'), route('skills.show', 'inactive-skill'));

        $this->get(route('skills.show', 'empty-skill'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow');
    });

    it('keeps proof of work with consent none out of skill pages and the sitemap', function () {
        $skill = Skill::factory()->create(['slug' => 'modelling']);
        $none = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::None]);
        ProofOfWork::factory()->published()->create(['user_id' => $none['contributor']->id, 'project_id' => $none['project']->id])->skills()->attach($skill->id);

        expect(sitemapUrls())->not->toContain(route('skills.show', 'modelling'));
    });

    it('drops directory and work links when their flags are off', function () {
        Organization::factory()->verified()->create(['slug' => 'public-org', 'is_public' => true]);
        app(FeatureFlags::class)->set(FeatureFlag::PublicDirectories, false);
        app(FeatureFlags::class)->flush();

        expect(sitemapUrls())->not->toContain(route('organizations.index'), route('contributors.index'));
    });

    it('serves robots.txt with the private areas disallowed and the sitemap listed', function () {
        $this->get(route('robots'))->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /admin')->assertSee('Disallow: /app/')->assertSee('Disallow: /login')->assertSee('Sitemap: '.route('sitemap'));
    });
});

describe('navigation', function () {
    it('links the public directories and work only while their flags are on', function () {
        $this->get('/')->assertSee('Opportunities')->assertSee('Contributors')->assertSee('Organizations')->assertSee('Our work');

        foreach ([FeatureFlag::OpenRoles, FeatureFlag::PublicDirectories, FeatureFlag::ProofOfWorkPublic] as $flag) {
            app(FeatureFlags::class)->set($flag, false);
        }
        app(FeatureFlags::class)->flush();
        app(PublicNavigation::class)->flush();

        $html = $this->get('/')->getContent();
        $header = substr($html, strpos($html, '<header'), strpos($html, '</header>') - strpos($html, '<header'));

        expect($header)->not->toContain('Opportunities')->not->toContain('Contributors')->not->toContain('Organizations')->not->toContain('Our work');
    });

    it('links About, Terms and Privacy in the footer only when those pages exist for visitors', function () {
        $footer = function (): string {
            app(PublicNavigation::class)->flush();
            $html = $this->get('/')->getContent();

            return substr($html, strpos($html, '<footer'));
        };

        expect($footer())->toContain('How it works')->toContain('Contact')->toContain('FAQ')->not->toContain('>About<')->not->toContain('>Terms<')->not->toContain('>Privacy<');

        Page::factory()->create(['slug' => 'about']);
        $this->seed(AgreementTemplateSeeder::class);

        expect($footer())->toContain('>About<')->not->toContain('>Terms<');

        AgreementTemplate::query()->where('type', AgreementType::Terms)->update(['is_active' => false]);
        AgreementTemplate::factory()->ofType(AgreementType::Terms)->create(['version' => 2, 'body_html' => '<p>Real terms.</p>', 'is_active' => true]);

        expect($footer())->toContain('>Terms<')->not->toContain('>Privacy<');
    });

    it('shows the same menu on the other public pages', function () {
        $this->get(route('contact'))->assertSee('aria-label="Main"', false)->assertSee('aria-label="Footer"', false);
        app(HomepageBuilder::class)->flush();
    });
});
