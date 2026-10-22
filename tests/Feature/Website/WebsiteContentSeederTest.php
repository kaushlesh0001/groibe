<?php

use App\Enums\FaqAudience;
use App\Models\CmsSection;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Testimonial;
use App\Rules\PublicCopy;
use App\Support\Website\HomepageSections;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\WebsiteContentSeeder;

beforeEach(fn () => $this->seed(WebsiteContentSeeder::class));

it('seeds the eleven homepage sections, switched on, in the spec order, with empty content', function () {
    $sections = CmsSection::query()->ordered()->get();

    expect($sections->pluck('key')->all())->toBe(HomepageSections::keys())
        ->and($sections->every(fn (CmsSection $section) => $section->is_active && ! $section->needs_review && $section->content === []))->toBeTrue();
});

it('seeds ten general, six contributor and six client questions, all drafts that need review', function () {
    $count = fn (FaqAudience $audience) => Faq::query()->forAudience($audience)->count();

    expect($count(FaqAudience::General))->toBe(10)->and($count(FaqAudience::Contributor))->toBe(6)->and($count(FaqAudience::Client))->toBe(6)
        ->and(Faq::query()->where('needs_review', true)->where('is_active', false)->count())->toBe(22)
        ->and(Faq::query()->active()->count())->toBe(0);
});

it('seeds the About page as a hidden draft whose founder line holds only the name, city and email the founder gave', function () {
    $about = Page::query()->where('slug', 'about')->firstOrFail();

    expect($about->is_active)->toBeFalse()->and($about->needs_review)->toBeTrue()
        ->and($about->body_html)->toContain('is founded by')->toContain('Kaushlesh Kumar')->toContain('Kolkata, India')->toContain('contact@groibe.com')
        // No biography, no past roles, no phone number, no street address.
        ->not->toMatch('/(formerly|previously|years of experience|MBA|graduate|\+91|Road|Street)/i');
});

it('seeds no testimonial, because a testimonial must be a real quote', function () {
    expect(Testimonial::query()->count())->toBe(0);
});

it('lets nothing unreviewed reach a public page', function () {
    $home = $this->get('/')->assertOk()->getContent();

    expect($home)->toContain('One platform. Two sides. One delivery loop.')
        ->not->toContain('Is Groibe a job portal?')->not->toContain('What is Groibe?')->not->toContain('What people say');

    $this->get(route('faq'))->assertOk()->assertSee('No questions have been published yet')->assertDontSee('Is Groibe a job portal?');
    $this->get(route('about'))->assertNotFound();
    expect($this->get(route('sitemap'))->getContent())->not->toContain(route('about'));
});

it('shows a question once an admin approves it, and no sooner', function () {
    $faq = Faq::query()->where('question', 'What is Groibe?')->firstOrFail();

    $faq->update(['needs_review' => false]);
    $this->get(route('faq'))->assertDontSee('What is Groibe?');

    $faq->update(['is_active' => true]);
    $this->get(route('faq'))->assertSee('What is Groibe?')->assertDontSee('Is Groibe a job portal?');
});

it('is safe to run twice and never overwrites what an admin changed', function () {
    $faq = Faq::query()->where('question', 'What is Groibe?')->firstOrFail();
    $faq->update(['needs_review' => false, 'is_active' => true, 'answer' => 'The founder\'s own answer.']);
    CmsSection::query()->where('key', 'hero')->first()->update(['is_active' => false, 'content' => ['heading' => 'Where Work Finds']]);
    Page::query()->where('slug', 'about')->first()->update(['needs_review' => false, 'is_active' => true, 'title' => 'About us']);

    $before = [Faq::query()->count(), CmsSection::query()->count(), Page::query()->count()];

    $this->seed(WebsiteContentSeeder::class);

    expect([Faq::query()->count(), CmsSection::query()->count(), Page::query()->count()])->toBe($before)
        ->and($faq->fresh()->answer)->toBe('The founder\'s own answer.')->and($faq->fresh()->is_active)->toBeTrue()
        ->and(CmsSection::query()->where('key', 'hero')->first()->is_active)->toBeFalse()
        ->and(CmsSection::query()->where('key', 'hero')->first()->content)->toBe(['heading' => 'Where Work Finds'])
        ->and(Page::query()->where('slug', 'about')->first()->title)->toBe('About us');
});

it('follows the public copy rules in every seeded question, answer and the About text', function () {
    $texts = [...Faq::query()->get()->flatMap(fn (Faq $faq) => [$faq->question, $faq->answer])->all(), (string) Page::query()->where('slug', 'about')->value('body_html')];

    expect(count($texts))->toBe(45);

    foreach ($texts as $text) {
        expect(PublicCopy::violation($text))->toBeNull($text);
        expect($text)->not->toContain('[CHECK]')->not->toMatch('/\b\d[\d,]*\+?\s*(members|contributors|professionals|clients|projects|companies)\b/i');
    }
});

it('runs as part of the production database seeder', function () {
    CmsSection::query()->delete();
    Faq::query()->delete();

    $this->seed(DatabaseSeeder::class);

    expect(CmsSection::query()->count())->toBe(11)->and(Faq::query()->count())->toBe(22);
});
