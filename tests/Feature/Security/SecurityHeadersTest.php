<?php

use Illuminate\Support\Facades\Route;

const PUBLIC_PAGES = ['/', '/how-it-works', '/for-contributors', '/for-clients', '/contact', '/faq', '/opportunities', '/contributors', '/organizations', '/work', '/sitemap.xml', '/robots.txt', '/no-such-page'];

/** The directives of a Content-Security-Policy as name => sources. @return array<string, list<string>> */
function csp(string $header): array
{
    $directives = [];

    foreach (array_filter(array_map('trim', explode(';', $header))) as $directive) {
        $parts = preg_split('/\s+/', $directive);
        $directives[array_shift($parts)] = $parts;
    }

    return $directives;
}

describe('headers on every response', function () {
    it('sets the baseline headers on public, sign-in and admin pages', function (string $url) {
        $response = $this->get($url);

        expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
            ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()')->toContain('microphone=()')->toContain('geolocation=()')
            ->and($response->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin')
            ->and($response->headers->get('Content-Security-Policy'))->not->toBeNull();
    })->with([...PUBLIC_PAGES, '/login', '/register/contributor', '/forgot-password', '/admin/login', '/dashboard', '/up']);

    it('sets them on a redirect and on an error page', function () {
        $this->get('/dashboard')->assertRedirect()->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/definitely-missing')->assertNotFound()->assertHeader('Content-Security-Policy');
    });

    it('leaves a header the controller set on purpose alone', function () {
        Route::middleware('web')->get('/_header-test', fn () => response('ok')->header('X-Frame-Options', 'SAMEORIGIN'));

        $this->get('/_header-test')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff');
    });
});

describe('Content-Security-Policy', function () {
    it('is strict on the public pages: same-origin only, no inline code and no eval', function (string $url) {
        $policy = csp($this->get($url)->headers->get('Content-Security-Policy'));

        expect($policy['script-src'])->toBe(["'self'"])
            ->and($policy['style-src'])->toBe(["'self'"])
            ->and($policy['default-src'])->toBe(["'self'"])
            ->and($policy['frame-ancestors'])->toBe(["'none'"])
            ->and($policy['object-src'])->toBe(["'none'"])
            ->and($policy['base-uri'])->toBe(["'self'"])
            ->and($policy['form-action'])->toBe(["'self'"])
            ->and($policy['connect-src'])->toBe(["'self'"]);
    })->with(PUBLIC_PAGES);

    it('allows the inline scripts and eval that Livewire, Alpine and Filament need on the signed-in and admin pages, and nothing off-site', function (string $url) {
        $header = $this->get($url)->headers->get('Content-Security-Policy');
        $policy = csp($header);

        expect($policy['script-src'])->toContain("'self'")->toContain("'unsafe-inline'")->toContain("'unsafe-eval'")
            ->and($policy['frame-ancestors'])->toBe(["'none'"])
            ->and($policy['object-src'])->toBe(["'none'"])
            ->and($policy['base-uri'])->toBe(["'self'"])
            ->and($policy['form-action'])->toBe(["'self'"])
            ->and($header)->not->toMatch('~https?://|\*~');
    })->with(['/login', '/register/contributor', '/register/client', '/forgot-password', '/admin/login']);

    it('never allows another origin for scripts, styles, fonts, images or frames, anywhere', function (string $url) {
        $header = $this->get($url)->headers->get('Content-Security-Policy');

        expect($header)->not->toMatch('~https?://|ws://|wss://|\*~')->not->toContain('frame-src');
    })->with([...PUBLIC_PAGES, '/login', '/admin/login']);

    it('can run in report-only mode', function () {
        config(['groibe.security.csp_report_only' => true]);

        $response = $this->get('/');

        expect($response->headers->get('Content-Security-Policy-Report-Only'))->not->toBeNull()
            ->and($response->headers->get('Content-Security-Policy'))->toBeNull();
    });

    it('allows the Vite dev server only in the local environment and only while it runs', function () {
        $hot = public_path('hot');
        file_put_contents($hot, 'http://127.0.0.1:5173');

        try {
            app()->detectEnvironment(fn () => 'local');
            $local = $this->get('/')->headers->get('Content-Security-Policy');

            app()->detectEnvironment(fn () => 'production');
            $production = $this->get('/')->headers->get('Content-Security-Policy');
        } finally {
            @unlink($hot);
            app()->detectEnvironment(fn () => 'testing');
        }

        expect($local)->toContain('http://127.0.0.1:5173')->toContain('ws://127.0.0.1:5173')
            ->and($production)->not->toContain('127.0.0.1');
    });
});

describe('HSTS', function () {
    it('is sent on HTTPS only', function () {
        $secure = $this->withServerVariables(['HTTPS' => 'on'])->get('https://localhost/');
        $plain = $this->get('http://localhost/');

        expect($secure->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains')
            ->and($plain->headers->has('Strict-Transport-Security'))->toBeFalse();
    });

    it('can be switched off while a new host is tested', function () {
        config(['groibe.security.hsts_max_age' => 0]);

        expect($this->withServerVariables(['HTTPS' => 'on'])->get('https://localhost/')->headers->has('Strict-Transport-Security'))->toBeFalse();
    });
});

describe('public pages need no scripts of their own', function () {
    // Livewire remembers that a component rendered earlier in the same test process; a real request starts clean.
    beforeEach(fn () => Livewire\Livewire::flushState());

    it('send no script, no inline style and no off-site address', function (string $url) {
        $response = $this->get($url);

        if ($response->status() !== 200) {
            $this->markTestSkipped("{$url} is not served on an empty database.");
        }

        $html = $response->getContent();
        $scripts = preg_match_all('~<script\b([^>]*)>~i', $html, $matches) ? $matches[1] : [];

        // JSON-LD is data, not code, and the policy does not block it. The Vite bundle is a same-origin file.
        foreach ($scripts as $attributes) {
            expect($attributes)->toMatch('~type="application/ld\+json"|src="[^"]*(/build/|/@vite/)[^"]*"~');
        }

        expect($html)->not->toContain('livewire')
            ->and($html)->not->toContain('flux.js')
            ->and($html)->not->toMatch('~\sstyle="~i')
            ->and($html)->not->toMatch('~\son(click|load|error|submit)=~i')
            ->and($html)->not->toMatch('~x-data|wire:|@fluxAppearance|<script>~i');
    })->with(['/', '/how-it-works', '/for-contributors', '/for-clients', '/contact', '/faq']);
});
