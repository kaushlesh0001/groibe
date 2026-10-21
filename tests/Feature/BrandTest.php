<?php

use App\Enums\AdminRole;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Sow;
use App\Models\Task;
use App\Notifications\TaskAssigned;
use App\Services\Sows\SowPdf;
use Illuminate\Support\Facades\File;

/*
 * The brand (spec 10.3, SCR-017): tokens, logo files, icons, the tagline, and the contrast rules that go with the
 * colors. Contrast is computed from the tokens in app.css, so changing a color without re-checking it fails here.
 */

const BRAND_TOKENS = [
    'brand-navy' => '#042B65',
    'brand-green' => '#0EAE44',
    'brand-green-alt' => '#25A446',
    'brand-blue' => '#046CEA',
    'brand-teal' => '#06A2A7',
];

function cssToken(string $name): string
{
    preg_match('/--color-'.preg_quote($name, '/').':\s*(#[0-9A-Fa-f]{6})/', file_get_contents(resource_path('css/app.css')), $m);

    return strtoupper($m[1] ?? '');
}

/** WCAG 2.x relative luminance and contrast ratio. */
function luminance(string $hex): float
{
    [$r, $g, $b] = array_map(fn (string $c) => hexdec($c) / 255, str_split(ltrim($hex, '#'), 2));
    $lin = fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

    return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
}

function contrast(string $a, string $b): float
{
    [$hi, $lo] = [max(luminance($a), luminance($b)), min(luminance($a), luminance($b))];

    return ($hi + 0.05) / ($lo + 0.05);
}

/** @return list<string> every view and PHP file the app owns */
function ownedFiles(): array
{
    return collect(['app', 'resources/views', 'resources/css', 'config', 'routes'])
        ->flatMap(fn (string $dir) => File::allFiles(base_path($dir)))
        ->reject(fn ($file) => str_contains($file->getPathname(), 'resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'flux'))
        ->map(fn ($file) => $file->getPathname())
        ->values()
        ->all();
}

describe('tokens', function () {
    it('defines the five brand colors exactly as the brief gives them', function () {
        foreach (BRAND_TOKENS as $name => $hex) {
            expect(cssToken($name))->toBe($hex, $name);
        }
    });

    it('agrees with the spec', function () {
        $spec = file_get_contents(base_path('docs/GROIBE_MASTER_SPEC.md'));

        foreach (BRAND_TOKENS as $name => $hex) {
            expect($spec)->toContain("`{$name}` `{$hex}`");
        }

        expect($spec)->toContain('Where Ideas Find People')->not->toContain('Ideas find people. Work gets done.');
    });

    it('leaves no trace of the old brand colors or the old token name', function () {
        foreach (ownedFiles() as $path) {
            $text = file_get_contents($path);

            expect($text)->not->toContain('#001844', $path)->not->toContain('#14C352', $path)->not->toContain('#137AE5', $path);
            expect(preg_match('/\b(bg|text|fill|border|ring)-navy\b/', $text))->toBe(0, "{$path} uses the old navy token");
        }
    });

    it('uses Inter, self-hosted, and no other font', function () {
        $css = file_get_contents(resource_path('css/app.css'));

        expect($css)->toContain('@fontsource-variable/inter')->toContain("'Inter Variable'");

        foreach (ownedFiles() as $path) {
            expect(file_get_contents($path))->not->toMatch('/fonts\.(googleapis|bunny)\.|font-family:\s*[\'"]?(Roboto|Poppins|Montserrat|Open Sans)/i', $path);
        }
    });
});

describe('contrast (spec 10.3)', function () {
    it('measures each pairing the spec rules rely on', function () {
        $white = '#FFFFFF';
        $c = fn (string $a, string $b) => contrast($a === 'white' ? $white : cssToken($a), $b === 'white' ? $white : cssToken($b));

        expect($c('brand-navy', 'white'))->toBeGreaterThanOrEqual(7.0)           // body text
            ->and($c('white', 'brand-navy'))->toBeGreaterThanOrEqual(7.0)         // text on navy surfaces
            ->and($c('brand-navy', 'brand-green'))->toBeGreaterThanOrEqual(4.5)   // green button label
            ->and($c('brand-green', 'brand-navy'))->toBeGreaterThanOrEqual(4.5)   // highlighted word on navy
            ->and($c('brand-blue', 'white'))->toBeGreaterThanOrEqual(4.5)         // links on light surfaces
            ->and($c('brand-green-alt', 'white'))->toBeGreaterThanOrEqual(3.0);   // graphics and large text on white
    });

    it('keeps the pairings the spec forbids out of the views', function () {
        // These fail AA by measurement: white on green, green text on white, blue on navy.
        expect(contrast('#FFFFFF', cssToken('brand-green')))->toBeLessThan(4.5)
            ->and(contrast(cssToken('brand-green'), '#FFFFFF'))->toBeLessThan(3.0)
            ->and(contrast(cssToken('brand-blue'), cssToken('brand-navy')))->toBeLessThan(4.5);

        foreach (ownedFiles() as $path) {
            if (! str_ends_with($path, '.blade.php')) {
                continue;
            }

            foreach (file($path) as $number => $line) {
                $where = "{$path}:".($number + 1);

                // A green fill never carries white text.
                if (preg_match('/\bbg-brand-green\b(?!-)/', $line)) {
                    expect($line)->not->toMatch('/\btext-white\b/', $where);
                }

                // Blue text never sits on navy. A logo is not text: the dark logo files carry blue on navy by design
                // (supplied artwork, not recolored), so lines that only place a logo are exempt. Text lines are not.
                $placesLogo = str_contains($line, '<x-app-logo') || str_contains($line, 'brand/groibe-logo');

                if (str_contains($line, 'bg-brand-navy') && ! $placesLogo) {
                    expect($line)->not->toContain('text-brand-blue', $where);
                }
            }
        }

        // Green text is only for navy surfaces: the one highlighted word in the hero.
        foreach (ownedFiles() as $path) {
            if (str_ends_with($path, '.blade.php') && str_contains(file_get_contents($path), 'text-brand-green')) {
                expect(basename($path))->toBe('welcome.blade.php');
            }
        }
    });
});

describe('logo and icons', function () {
    it('ships the supplied logo files unchanged in shape', function () {
        foreach (['groibe-logo', 'groibe-logo-clean', 'groibe-logo-dark', 'groibe-logo-white', 'groibe-ring', 'groibe-ring-white'] as $file) {
            expect(public_path("brand/{$file}.svg"))->toBeFile();
        }

        [$w, $h] = getimagesize(public_path('brand/groibe-logo.png'));
        expect(round($w / $h, 2))->toBe(round(2021 / 507, 2)); // the wordmark ratio in its SVG viewBox

        // The resized copy used in emails and PDFs keeps the ratio (within a pixel of rounding) and stays small.
        [$sw, $sh] = getimagesize(public_path('brand/groibe-logo-small.png'));
        expect(abs($sw / $sh - $w / $h))->toBeLessThan(0.02)->and(filesize(public_path('brand/groibe-logo-small.png')))->toBeLessThan(40_000);

        [$rw, $rh] = getimagesize(public_path('brand/groibe-ring.png'));
        expect($rw)->toBe($rh);

        expect(getimagesize(public_path('apple-touch-icon.png'))[0])->toBe(180)
            ->and(getimagesize(public_path('brand/icon-512.png'))[0])->toBe(512);
    });

    it('exempts logo lines from the blue-on-navy text rule without exempting text', function () {
        // The exemption is by line: a line that places a logo is skipped, any other navy line with blue text still fails.
        $logoLine = '<div class="bg-brand-navy"><x-app-logo variant="dark" /></div>';
        $textLine = '<div class="bg-brand-navy text-brand-blue">Hello</div>';

        $exempt = fn (string $line) => str_contains($line, '<x-app-logo') || str_contains($line, 'brand/groibe-logo');

        expect($exempt($logoLine))->toBeTrue()->and($exempt($textLine))->toBeFalse();

        // Navy surfaces use the dark logo, not the all-white one, in the public layout.
        $layout = file_get_contents(resource_path('views/components/layouts/public.blade.php'));
        expect($layout)->toContain('variant="dark"')->not->toContain('variant="white"');
    });

    it('has a real favicon, not the empty placeholder', function () {
        expect(filesize(public_path('favicon.ico')))->toBeGreaterThan(1000)
            ->and(file_get_contents(public_path('favicon.ico')))->toBe(file_get_contents(public_path('brand/favicon.ico')));
    });

    it('never fixes both dimensions of the logo, so it cannot be stretched', function () {
        foreach (ownedFiles() as $path) {
            foreach (file($path) as $number => $line) {
                if (preg_match('/<x-app-logo[^>]*class="[^"]*"/', $line, $m)) {
                    expect($m[0])->not->toMatch('/\b(size-|w-(?!auto))/', "{$path}:".($number + 1));
                }
            }
        }
    });

    it('only serves logos from the brand folder', function () {
        foreach (ownedFiles() as $path) {
            expect(file_get_contents($path))->not->toMatch('/laravel\.com\/img|livewire-starter-kit.*logo/i', $path);
        }
    });
});

describe('pages', function () {
    it('shows the tagline, dark logo, navy header and footer and one green word on the home page', function () {
        $html = $this->get('/')->assertOk()->getContent();

        expect($html)->toContain('Where Ideas Find <span class="text-brand-green">People</span>')
            ->and(substr_count($html, 'brand/groibe-logo-dark.svg'))->toBe(2) // header and footer
            ->and($html)->not->toContain('brand/groibe-logo-white.svg')
            ->and($html)->toContain('<title>Where Ideas Find People | ')
            ->and($html)->toContain('bg-brand-navy')
            ->and($html)->not->toContain('—')
            ->and($html)->not->toContain('Ideas find people. Work gets done');
    });

    it('invents no numbers on the public pages', function () {
        $text = strip_tags($this->get('/')->getContent());

        expect($text)->not->toMatch('/\b\d[\d,]*\+?\s*(members|contributors|professionals|clients|projects|companies)\b/i');
    });

    it('links the icons and sets the theme color on public, auth and app pages', function (string $path) {
        $html = $this->get($path)->assertOk()->getContent();

        expect($html)->toContain('rel="icon"')->toContain('favicon.ico')
            ->and($html)->toContain('rel="apple-touch-icon"')->toContain('apple-touch-icon.png')
            ->and($html)->toContain('<meta name="theme-color" content="#042B65">');
    })->with(['/', '/login', '/register/client']);

    it('shows the logo on the sign-in page and on the signed-in app', function () {
        $this->get('/login')->assertOk()->assertSee('brand/groibe-logo-clean.svg', false);

        $this->actingAs(contributorUser())->get('/dashboard')->assertRedirect();
        $this->actingAs(clientUser())->get('/app/client')->assertOk()->assertSee('brand/groibe-logo-clean.svg', false);
    });

    it('brands the admin panel', function () {
        $this->get('/admin/login')->assertOk()
            ->assertSee('brand/groibe-logo-clean.svg', false)
            ->assertSee('favicon.ico', false);
    });

    it('keeps the home page navy hero accessible', function () {
        $html = $this->get('/')->getContent();

        expect($html)->toContain('<h1')->and(substr_count($html, '<h1'))->toBe(1)
            ->and($html)->toContain('alt="Groibe"');
    });
});

describe('email and documents', function () {
    it('puts the PNG logo and brand colors in emails', function () {
        $task = Task::factory()->create();
        $task->loadMissing('milestone.project');
        $html = (string) (new TaskAssigned($task, $task->milestone->project))->toMail(contributorUser())->render();

        expect($html)->toContain('brand/groibe-logo-small.png')->and($html)->not->toContain('brand/groibe-logo-clean.svg')
            ->and($html)->toContain('0EAE44')->toContain('042B65')   // green button, navy label and heading
            ->and($html)->not->toContain('laravel.com/img');

        // Links use the brand blue; this message has none, so check the theme the links come from.
        $theme = file_get_contents(resource_path('views/vendor/mail/html/themes/default.css'));
        expect($theme)->toContain('#046CEA')->and($theme)->not->toContain('#18181b;'.'
}

a img');
    });

    it('puts the logo in the SOW print view and the PDF', function () {
        $sow = Sow::factory()->create(['project_id' => Project::factory()->status(ProjectStatus::SowSent)->create()->id]);
        $sow->load('project.organization', 'accepter');

        $print = view('sows.document', ['sow' => $sow, 'forPdf' => false])->render();
        $pdfView = view('sows.document', ['sow' => $sow, 'forPdf' => true])->render();

        expect($print)->toContain('brand/groibe-logo-clean.svg')->and($print)->toContain('#042B65')
            ->and($pdfView)->toContain(public_path('brand/groibe-logo-small.png'))
            ->and(app(SowPdf::class)->render($sow))->toStartWith('%PDF');
    });

    it('ships an admin who can still reach the panel after the rebrand', function () {
        $this->actingAs(adminUser(AdminRole::DeliveryManager))->get('/admin')->assertOk();
    });
});
