<?php

use App\Enums\AgreementType;
use App\Enums\FaqAudience;
use App\Enums\FeaturedItemType;
use App\Enums\ProjectStatus;
use App\Enums\ShowcaseConsent;
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
use App\Rules\PublicCopy;
use App\Services\Website\HomepageBuilder;
use App\Support\Website\HomepageDefaults;
use App\Support\Website\HomepageSections;
use App\Support\Website\StaticPageDefaults;
use Illuminate\Support\Facades\File;

/**
 * Seeds one of everything public, with the homepage showing every section, and returns the address of each public page.
 *
 * @return array<string, string>
 */
function polishPages(): array
{
    foreach (array_keys(HomepageSections::TITLES) as $i => $key) {
        CmsSection::factory()->create(['key' => $key, 'title' => HomepageSections::title($key), 'sort' => $i + 1, 'content' => []]);
    }

    $skill = Skill::factory()->create(['name' => 'Financial Modelling', 'slug' => 'financial-modelling']);
    $role = OpenRole::factory()->published()->public()->create(['title' => 'Survey analyst', 'slug' => 'survey-analyst']);
    $role->skills()->attach($skill->id);
    $org = Organization::factory()->verified()->create(['name' => 'Kestrel Foods', 'slug' => 'kestrel-foods', 'is_public' => true, 'industry' => 'Manufacturing']);
    $seat = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::Anonymous]);
    $proof = ProofOfWork::factory()->published()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id, 'title' => 'Regional market sizing', 'slug' => 'regional-market-sizing']);
    $proof->skills()->attach($skill->id);
    $seat['contributor']->skills()->attach($skill->id, ['level' => 'expert']);

    FeaturedItem::factory()->of(FeaturedItemType::OpenRole, $role->id)->create();
    FeaturedItem::factory()->of(FeaturedItemType::Organization, $org->id)->create();
    FeaturedItem::factory()->of(FeaturedItemType::ProofOfWork, $proof->id)->create();
    FeaturedItem::factory()->of(FeaturedItemType::Contributor, $seat['contributor']->id)->create();
    Testimonial::factory()->create(['quote' => 'Delivered on time.']);
    foreach (FaqAudience::cases() as $audience) {
        Faq::factory()->audience($audience)->create();
    }

    Page::factory()->create(['slug' => 'about', 'title' => 'About Groibe']);
    Page::factory()->create(['slug' => 'careers', 'title' => 'Careers']);
    foreach ([AgreementType::Terms, AgreementType::Privacy] as $type) {
        AgreementTemplate::factory()->ofType($type)->create(['body_html' => '<h2>Part one</h2><p>Real wording.</p>', 'is_active' => true]);
    }

    return [
        'home' => '/', 'how-it-works' => route('how-it-works'), 'for-contributors' => route('for-contributors'), 'for-clients' => route('for-clients'),
        'about' => route('about'), 'faq' => route('faq'), 'contact' => route('contact'), 'terms' => route('terms'), 'privacy' => route('privacy'),
        'careers' => url('careers'), 'opportunities' => route('opportunities.index'), 'role' => route('opportunities.show', $role->slug),
        'contributors' => route('contributors.index'), 'contributor' => route('contributors.show', $seat['contributor']->profile->slug),
        'organizations' => route('organizations.index'), 'organization' => route('organizations.show', $org->slug),
        'work' => route('work.index'), 'work item' => route('work.show', $proof->slug), 'skill' => route('skills.show', $skill->slug),
    ];
}

/** The tags of a kind in a page, as attribute maps. @return list<array<string, string>> */
function polishTags(string $html, string $tag): array
{
    preg_match_all('/<'.$tag.'\b([^>]*)>/i', $html, $matches);

    return array_map(function (string $attributes): array {
        preg_match_all('/([a-zA-Z:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $attributes, $pairs, PREG_SET_ORDER);

        return collect($pairs)->mapWithKeys(fn (array $pair): array => [strtolower($pair[1]) => $pair[2] !== '' ? $pair[2] : ($pair[3] ?? '')])->all();
    }, $matches[1]);
}

/** The public view files, without Blade comments. @return array<string, string> path => source */
function publicViewSources(): array
{
    $files = [
        resource_path('views/welcome.blade.php'),
        resource_path('views/components/layouts/public.blade.php'),
        ...collect(['open-role-card', 'proof-card', 'contributor-card', 'organization-card', 'faq-tabs', 'open-role-filters'])->map(fn (string $name): string => resource_path("views/components/{$name}.blade.php"))->all(),
        ...collect(File::allFiles(resource_path('views/public')))->map(fn ($file): string => $file->getPathname())->all(),
    ];

    return collect($files)->filter(fn (string $path): bool => is_file($path))
        ->mapWithKeys(fn (string $path): array => [$path => preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path))])
        ->all();
}

describe('accessibility', function () {
    it('gives every public page one h1, one main landmark, a language, a skip link and labelled navigation', function () {
        foreach (polishPages() as $name => $url) {
            $html = $this->get($url)->assertOk()->getContent();

            expect(substr_count($html, '<h1'))->toBe(1, "{$name} h1 count")
                ->and(substr_count($html, '<main'))->toBe(1, "{$name} main count")
                ->and($html)->toMatch('/<html lang="[a-z-]+"/')->toContain('Skip to content', 'href="#', 'id="main"')
                ->and($html)->toContain('aria-label="Main"')->toContain('aria-label="Footer"')
                ->and($html)->toContain('<meta name="viewport" content="width=device-width, initial-scale=1.0" />');
        }
    });

    it('never skips a heading level', function () {
        foreach (polishPages() as $name => $url) {
            preg_match_all('/<h([1-6])\b/i', $this->get($url)->getContent(), $m);
            $previous = 0;

            foreach ($m[1] as $level) {
                expect((int) $level - $previous)->toBeLessThanOrEqual(1, "{$name}: h{$previous} is followed by h{$level}");
                $previous = (int) $level;
            }
        }
    });

    it('keeps one h1 on the homepage even when Admin switches the hero off', function () {
        polishPages();
        CmsSection::query()->where('key', 'hero')->update(['is_active' => false]);
        app(HomepageBuilder::class)->flush();

        $html = $this->get('/')->assertOk()->getContent();

        expect(substr_count($html, '<h1'))->toBe(1)->and($html)->toContain('href="#main"');
    });

    it('labels every form field and gives every image a text alternative', function () {
        foreach (polishPages() as $name => $url) {
            $html = $this->get($url)->getContent();
            preg_match_all('/<label[^>]*\bfor="([^"]+)"/i', $html, $labels);

            foreach (['input', 'textarea', 'select'] as $tag) {
                foreach (polishTags($html, $tag) as $field) {
                    if (in_array($field['type'] ?? 'text', ['hidden', 'submit', 'button'], true)) {
                        continue;
                    }

                    expect(isset($field['id']) && in_array($field['id'], $labels[1], true) || isset($field['aria-label']))->toBeTrue("{$name}: a {$tag} has no label");
                }
            }

            foreach (polishTags($html, 'img') as $image) {
                expect(array_key_exists('alt', $image))->toBeTrue("{$name}: an image has no alt attribute");
            }
        }
    });

    it('gives every public page its own title and a description', function () {
        $titles = [];

        foreach (polishPages() as $name => $url) {
            $html = $this->get($url)->getContent();
            preg_match('/<title>(.*?)<\/title>/s', $html, $title);

            expect($title[1] ?? '')->not->toBe('', "{$name} title")->and(in_array($title[1] ?? '', $titles, true))->toBeFalse("{$name} repeats a title");
            expect($html)->toMatch('/<meta name="description" content="[^"]{10,}"/');
            $titles[] = $title[1];
        }
    });

    it('uses no positive tabindex and no colour pairing that fails contrast', function () {
        foreach (publicViewSources() as $path => $source) {
            expect($source)->not->toMatch('/tabindex="[1-9]/', $path)
                ->and($source)->not->toContain('text-brand-teal')
                ->and($source)->not->toMatch('/\btext-(zinc|gray|slate)-(300|400|500)\b/', $path)
                ->and($source)->not->toMatch('/\btext-white\b[^\n]*\bbg-brand-green\b|\bbg-brand-green\b[^\n]*\btext-white\b/', $path);
        }
    });
});

describe('no third-party requests', function () {
    it('loads no script, stylesheet, image, frame or media from another host on any public page', function () {
        $host = parse_url(config('app.url'), PHP_URL_HOST);

        foreach (polishPages() as $name => $url) {
            $html = $this->get($url)->getContent();

            foreach (['script' => 'src', 'img' => 'src', 'iframe' => 'src', 'source' => 'src', 'video' => 'src', 'audio' => 'src', 'embed' => 'src', 'object' => 'data', 'link' => 'href'] as $tag => $attribute) {
                foreach (polishTags($html, $tag) as $element) {
                    $target = $element[$attribute] ?? '';

                    if (str_starts_with($target, 'http') || str_starts_with($target, '//')) {
                        expect(parse_url($target, PHP_URL_HOST))->toBeIn([$host, 'localhost', '127.0.0.1', '[::1]', 'groibe.test'], "{$name}: {$tag} loads {$target}");
                    }
                }
            }

            expect($html)->not->toMatch('/@import\s+url\(\s*[\'"]?https?:/i')->not->toMatch('/url\(\s*[\'"]?https?:\/\/(?!localhost|127\.0\.0\.1|\[::1\])/i');
        }
    });

    it('has no remote font, script or tracking reference in the public views or the stylesheet', function () {
        foreach ([...array_keys(publicViewSources()), resource_path('css/app.css'), resource_path('js/app.js')] as $path) {
            expect(file_get_contents($path))->not->toMatch('~(fonts\.(googleapis|gstatic|bunny)|googletagmanager|google-analytics|facebook\.net|hotjar|cdn\.jsdelivr|unpkg\.com|cdnjs)~i', $path);
        }
    });
});

describe('public copy', function () {
    it('has no em dash and no AI wording on any rendered public page', function () {
        foreach (polishPages() as $name => $url) {
            $html = $this->get($url)->getContent();
            $text = html_entity_decode(strip_tags(preg_replace('/<(script|style)\b.*?<\/\1>/is', '', $html)), ENT_QUOTES | ENT_HTML5);

            expect(PublicCopy::violation($text))->toBeNull("{$name}: ".PublicCopy::violation($text));
        }
    });

    it('has no em dash and no AI wording in the public view files', function () {
        foreach (publicViewSources() as $path => $source) {
            $text = preg_replace('/@php.*?@endphp|\{\{.*?\}\}|\{!!.*?!!\}|<[^>]+>/s', ' ', $source);

            expect(PublicCopy::violation($text))->toBeNull("{$path}: ".PublicCopy::violation((string) $text));
        }
    });

    it('has no em dash and no AI wording in the built-in text', function () {
        $strings = [];
        $collect = function (array $values) use (&$strings, &$collect): void {
            foreach ($values as $value) {
                is_array($value) ? $collect($value) : $strings[] = (string) $value;
            }
        };

        foreach (HomepageSections::keys() as $key) {
            $collect(HomepageDefaults::for($key));
        }

        $collect(StaticPageDefaults::forContributors());
        $collect(StaticPageDefaults::forClients());

        expect(count($strings))->toBeGreaterThan(50);

        foreach ($strings as $text) {
            expect(PublicCopy::violation($text))->toBeNull($text);
        }
    });

    it('invents no numbers in the built-in text', function () {
        $text = strip_tags($this->get(route('for-clients'))->getContent().$this->get(route('for-contributors'))->getContent().$this->get(route('how-it-works'))->getContent());

        expect($text)->not->toMatch('/\b\d[\d,]*\+?\s*(members|contributors|professionals|clients|projects|companies|years|%)/i');
    });
});
