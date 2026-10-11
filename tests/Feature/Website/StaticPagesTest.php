<?php

use App\Models\CmsSection;
use App\Models\Faq;
use App\Models\Page;
use App\Rules\PublicCopy;
use App\Support\Website\PageSlug;

describe('built-in pages', function () {
    it('shows How it works, For contributors and For clients without any admin content', function (string $route, string $text) {
        $this->get(route($route))->assertOk()->assertSee($text)->assertSee('rel="canonical"', false)->assertSee('og:title', false);
    })->with([
        ['how-it-works', 'Five steps, from the first brief to proof of the work.'],
        ['for-contributors', 'Clients never hire you directly.'],
        ['for-clients', 'You buy outcomes from Groibe.'],
    ]);

    it('uses the how it works wording an admin set on the homepage section', function () {
        CmsSection::factory()->create(['key' => 'how_it_works', 'content' => ['heading' => 'Our five steps']]);

        $this->get(route('how-it-works'))->assertOk()->assertSee('Our five steps');
    });

    it('keeps em dashes and AI wording out of the built-in pages', function (string $route) {
        $text = html_entity_decode(strip_tags($this->get(route($route))->getContent()));

        expect(PublicCopy::violation($text))->toBeNull();
    })->with(['how-it-works', 'for-contributors', 'for-clients', 'faq', 'contact']);

    it('answers 404 for About until it is published in Admin', function () {
        $this->get(route('about'))->assertNotFound();

        Page::factory()->needsReview()->create(['slug' => 'about', 'title' => 'About Groibe']);
        $this->get(route('about'))->assertNotFound();

        Page::query()->where('slug', 'about')->update(['needs_review' => false, 'is_active' => true]);
        $this->get(route('about'))->assertOk()->assertSee('About Groibe');
    });

    it('lets a page written in Admin replace a built-in page', function () {
        Page::factory()->create(['slug' => 'for-clients', 'title' => 'For clients, our way', 'body_html' => '<p>Custom client wording.</p>']);

        $this->get(route('for-clients'))->assertOk()->assertSee('Custom client wording.')->assertDontSee('You buy outcomes from Groibe.');
    });
});

describe('faq page', function () {
    it('shows an honest empty state when there are no questions, and the questions once there are', function () {
        $this->get(route('faq'))->assertOk()->assertSee('No questions have been published yet')->assertDontSee('FAQPage', false);

        Faq::factory()->create(['question' => 'How do I join?', 'answer' => 'Create an account.']);
        Faq::factory()->needsReview()->create(['question' => 'Draft question?']);

        $this->get(route('faq'))->assertOk()->assertSee('How do I join?')->assertSee('FAQPage', false)->assertDontSee('Draft question?')->assertDontSee('No questions have been published yet');
    });
});

describe('CMS pages', function () {
    it('serves an active page at its address with its own title, description and canonical link', function () {
        Page::factory()->create(['slug' => 'careers', 'title' => 'Careers', 'body_html' => '<p>We are not hiring right now.</p>', 'meta_description' => 'Work with Groibe.']);

        $this->get('/careers')->assertOk()->assertSee('We are not hiring right now.')
            ->assertSee('<meta name="description" content="Work with Groibe.">', false)
            ->assertSee('rel="canonical" href="'.url('careers').'"', false);
    });

    it('answers 404 for an inactive page, a page that needs review, an unknown address and a nested path', function () {
        Page::factory()->inactive()->create(['slug' => 'old-page']);
        Page::factory()->needsReview()->create(['slug' => 'draft-page']);
        Page::factory()->create(['slug' => 'careers']);

        $this->get('/old-page')->assertNotFound();
        $this->get('/draft-page')->assertNotFound();
        $this->get('/no-such-page')->assertNotFound();
        $this->get('/careers/extra')->assertNotFound();
    });

    it('strips scripts and event handlers from the page body', function () {
        Page::factory()->create(['slug' => 'careers', 'body_html' => '<p onclick="steal()">Hello</p><script>alert(1)</script><img src=x onerror="steal()">']);

        $html = $this->get('/careers')->assertOk()->getContent();

        expect($html)->not->toContain('<script>alert(1)</script>')->not->toContain('onclick')->not->toContain('onerror')->toContain('Hello');
    });

    it('never shadows a real address', function () {
        Page::factory()->create(['slug' => 'login', 'body_html' => '<p>Not the sign-in page.</p>']);
        Page::factory()->create(['slug' => 'dashboard', 'body_html' => '<p>Not the dashboard.</p>']);

        $this->get('/login')->assertOk()->assertDontSee('Not the sign-in page.');
        $this->get('/dashboard')->assertRedirect();
    });

    it('refuses a reserved or routed address in the admin rule but leaves the four built-in pages open', function (string $slug, bool $reserved) {
        expect(PageSlug::isReserved($slug))->toBe($reserved);
    })->with([
        ['admin', true], ['login', true], ['dashboard', true], ['opportunities', true], ['terms', true], ['contact', true], ['sitemap.xml', true],
        ['how-it-works', false], ['for-contributors', false], ['for-clients', false], ['about', false], ['careers', false],
    ]);

    it('does not serve a page to a non-GET request', function () {
        $this->post('/careers')->assertStatus(405);
    });
});
