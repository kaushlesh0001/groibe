<?php

use App\Enums\ProjectStatus;
use App\Enums\ShowcaseConsent;
use App\Models\Faq;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\ProofOfWork;
use App\Models\Skill;
use App\Support\Seo\JsonLd;

/**
 * Structured data (spec 10.4, SUGGESTIONS 349). Blade reads the text `@context` as its own directive, so a page that wrote it
 * in a view printed PHP instead and search engines could not read the data. These tests decode what each page really sends.
 *
 * @return list<array<string, mixed>> every JSON-LD block of a page, decoded
 */
function structuredData(string $url): array
{
    $html = test()->get($url)->assertOk()->getContent();

    // Whatever Blade did with the text, no PHP may reach the page.
    expect($html)->not->toContain('<?php')->and($html)->not->toContain('__contextArgs');

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $blocks);
    expect($blocks[1])->not->toBeEmpty('the page has no structured data');

    return array_map(function (string $json): array {
        $decoded = json_decode($json, true);
        expect(json_last_error())->toBe(JSON_ERROR_NONE, 'the structured data is not valid JSON: '.$json)->and($decoded)->toBeArray();

        return $decoded;
    }, $blocks[1]);
}

describe('structured data on every public page', function () {
    it('is readable JSON with a context and a type on each page that has it', function (string $page, string $type) {
        $skill = Skill::factory()->create(['name' => 'Financial Modelling', 'slug' => 'financial-modelling']);
        Faq::factory()->create();
        $organization = Organization::factory()->verified()->create(['slug' => 'kestrel-foods', 'name' => 'Kestrel Foods', 'is_public' => true]);
        $role = OpenRole::factory()->published()->public()->create(['title' => 'Public modeller']);
        $seat = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::Anonymous]);
        $proof = ProofOfWork::factory()->published()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id, 'title' => 'Shown model']);

        $url = match ($page) {
            'home' => route('home'),
            'faq' => route('faq'),
            'opportunity' => route('opportunities.show', $role->slug),
            'organization' => route('organizations.show', $organization->slug),
            'skill' => route('skills.show', $skill->slug),
            'work' => route('work.show', $proof->slug),
        };

        $blocks = structuredData($url);

        foreach ($blocks as $data) {
            expect($data['@context'])->toBe('https://schema.org')->and($data['@type'])->toBeString();
        }

        expect(collect($blocks)->pluck('@type')->all())->toContain($type);
    })->with([
        'home' => ['home', 'Organization'],
        'faq' => ['faq', 'FAQPage'],
        'opportunity' => ['opportunity', 'BreadcrumbList'],
        'organization' => ['organization', 'BreadcrumbList'],
        'skill' => ['skill', 'BreadcrumbList'],
        'work' => ['work', 'BreadcrumbList'],
    ]);

    it('keeps a name from closing the script tag', function () {
        $organization = Organization::factory()->verified()->create(['slug' => 'tricky', 'name' => 'Acme </script><b>x</b> & Co', 'is_public' => true]);

        $blocks = structuredData(route('organizations.show', $organization->slug));
        $names = collect($blocks)->pluck('itemListElement')->flatten(1)->pluck('name');

        expect($names)->toContain('Acme </script><b>x</b> & Co');
        expect(test()->get(route('organizations.show', $organization->slug))->getContent())->not->toContain('</script><b>x</b>');
    });

    it('adds the context itself and escapes what could break out of a script', function () {
        $json = JsonLd::json(['@type' => 'Thing', 'name' => '</script> & <b>', 'url' => 'https://example.test/a/b']);

        expect($json)->not->toContain('</script>')->and($json)->not->toContain('<b>')
            ->and($json)->toContain('https://example.test/a/b')
            ->and(json_decode($json, true))->toBe(['@context' => 'https://schema.org', '@type' => 'Thing', 'name' => '</script> & <b>', 'url' => 'https://example.test/a/b']);
    });

    it('has no view that writes the context by hand', function () {
        $offenders = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => str_contains($file->getContents(), '@context'))
            ->map(fn ($file) => $file->getRelativePathname())->values()->all();

        expect($offenders)->toBe([], 'write structured data through App\Support\Seo\JsonLd, not by hand: Blade reads @context as a directive');
    });
});
