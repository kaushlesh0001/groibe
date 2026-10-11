<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\CmsSection;
use App\Models\Page;
use App\Support\Website\HomepageDefaults;
use App\Support\Website\StaticPageDefaults;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * How it works, For contributors, For clients and About (spec 10.2). An active Page with the same address, written in
 * Admin, always wins. Without one, the first three show the built-in text that restates the spec, and About answers
 * 404 until it is published, because it has no built-in wording.
 */
class StaticPageController extends Controller
{
    public function howItWorks(): View
    {
        return $this->cms('how-it-works') ?? view('public.pages.how-it-works', [
            'c' => HomepageDefaults::merged('how_it_works', CmsSection::query()->where('key', 'how_it_works')->value('content')),
        ]);
    }

    public function forContributors(): View
    {
        return $this->cms('for-contributors') ?? view('public.pages.audience', ['page' => StaticPageDefaults::forContributors(), 'slug' => 'for-contributors']);
    }

    public function forClients(): View
    {
        return $this->cms('for-clients') ?? view('public.pages.audience', ['page' => StaticPageDefaults::forClients(), 'slug' => 'for-clients']);
    }

    public function about(): View
    {
        return $this->cms('about') ?? abort(404);
    }

    /** Any other active Page, served at its own address by the fallback route. */
    public static function cms(string $slug): ?View
    {
        $page = Page::query()->active()->where('slug', $slug)->first();

        if ($page === null) {
            return null;
        }

        return view('public.pages.cms', [
            'page' => $page,
            'body' => Str::sanitizeHtml((string) $page->body_html),
        ]);
    }
}
