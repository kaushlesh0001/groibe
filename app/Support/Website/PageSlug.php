<?php

namespace App\Support\Website;

use Illuminate\Support\Facades\Route;

/**
 * Which addresses a CMS page may not take (spec 10.2). A page is served by the fallback route, so it can never replace a
 * route that exists. Rather than trust a hand-kept list alone, the address is also checked against the first segment of
 * every registered route. The four built-in pages (How it works, For contributors, For clients, About) stay open on
 * purpose: a page with that address replaces the built-in text.
 */
class PageSlug
{
    /** @var list<string> */
    public const REPLACEABLE = ['how-it-works', 'for-contributors', 'for-clients', 'about'];

    public static function isReserved(string $slug): bool
    {
        $slug = strtolower(trim($slug));

        if (in_array($slug, self::REPLACEABLE, true)) {
            return false;
        }

        if (in_array($slug, config('groibe.cms.reserved_slugs', []), true)) {
            return true;
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->isFallback) {
                continue;
            }

            if (strtolower(explode('/', trim($route->uri(), '/'))[0]) === $slug) {
                return true;
            }
        }

        return false;
    }
}
