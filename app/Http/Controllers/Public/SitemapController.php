<?php

namespace App\Http\Controllers\Public;

use App\Enums\FeatureFlag;
use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Http\Response;

/** `/sitemap.xml` and `/robots.txt` (spec 10.2, 10.4). Private profiles are never listed. Built per request so a visibility change takes effect at once. */
class SitemapController extends Controller
{
    private const MAX_URLS = 50000;

    public function sitemap(FeatureFlags $flags): Response
    {
        $urls = [['loc' => route('home'), 'lastmod' => null]];

        if ($flags->enabled(FeatureFlag::PublicDirectories->value)) {
            $urls[] = ['loc' => route('contributors.index'), 'lastmod' => null];

            $profiles = Profile::query()->publiclyVisible()
                ->orderBy('profiles.id')
                ->limit(self::MAX_URLS - count($urls))
                ->get(['profiles.id', 'profiles.slug', 'profiles.updated_at']);

            foreach ($profiles as $profile) {
                $urls[] = ['loc' => route('contributors.show', $profile->slug), 'lastmod' => $profile->updated_at?->toAtomString()];
            }
        }

        return response()->view('public.sitemap', ['urls' => $urls], 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /app/',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /invite/',
            'Sitemap: '.route('sitemap'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
