<?php

use Illuminate\Support\Facades\Route;

/*
 * A staging copy is never indexed (spec 10.4, 17.1, SCR-035): robots.txt disallows everything and names no sitemap, the
 * sitemap answers 404, and every web response carries `X-Robots-Tag: noindex, nofollow`. Production and local are unchanged.
 */

const NOINDEX_PAGES = ['/', '/how-it-works', '/contact', '/faq', '/opportunities', '/contributors', '/organizations', '/work', '/login', '/register/client', '/admin/login', '/no-such-page', '/up'];

it('has no physical robots.txt or sitemap.xml, which Apache would serve before Laravel', function () {
    expect(public_path('robots.txt'))->not->toBeFile()->and(public_path('sitemap.xml'))->not->toBeFile();
});

describe('on staging', function () {
    beforeEach(fn () => app()->detectEnvironment(fn () => 'staging'));

    it('asks every crawler to stay away and names no sitemap', function () {
        $response = $this->get(route('robots'))->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        expect(trim($response->getContent()))->toBe("User-agent: *\nDisallow: /")
            ->and($response->getContent())->not->toContain('Sitemap');
    });

    it('answers 404 for the sitemap', function () {
        $this->get('/sitemap.xml')->assertNotFound();
    });

    it('marks every web response noindex', function (string $url) {
        $this->get($url)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    })->with(NOINDEX_PAGES);

    it('marks a redirect noindex too', function () {
        $this->get('/dashboard')->assertRedirect()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    });

    it('replaces the index value a controller sets itself', function () {
        Route::middleware('web')->get('/_robots-test', fn () => response('ok')->header('X-Robots-Tag', 'index, follow'));

        $this->get('/_robots-test')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        expect($this->get('/_robots-test')->headers->all('x-robots-tag'))->toBe(['noindex, nofollow']);
    });
});

describe('outside staging', function () {
    it('leaves robots.txt, the sitemap and the headers as they were', function (string $environment) {
        app()->detectEnvironment(fn () => $environment);

        $this->get(route('robots'))->assertOk()
            ->assertSee('Disallow: /admin')->assertSee('Disallow: /app/')->assertSee('Sitemap: '.route('sitemap'))
            ->assertDontSee("Disallow: /\n", false);

        $this->get('/sitemap.xml')->assertOk();
        $this->get('/')->assertHeaderMissing('X-Robots-Tag');

        Route::middleware('web')->get('/_robots-test', fn () => response('ok')->header('X-Robots-Tag', 'index, follow'));
        $this->get('/_robots-test')->assertHeader('X-Robots-Tag', 'index, follow');
    })->with(['testing', 'local', 'production']);

    it('does not mistake a name that only contains staging for staging', function () {
        app()->detectEnvironment(fn () => 'pre-staging');

        $this->get('/sitemap.xml')->assertOk();
        $this->get('/')->assertHeaderMissing('X-Robots-Tag');
    });
});
