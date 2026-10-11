<?php

namespace App\Http\Controllers\Public;

use App\Enums\FeatureFlag;
use App\Http\Controllers\Controller;
use App\Models\OpenRole;
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

        // Only public, published roles. Members-only, invite-only and every other state never appear (spec 10.4).
        if ($flags->enabled(FeatureFlag::OpenRoles->value)) {
            $urls[] = ['loc' => route('opportunities.index'), 'lastmod' => null];

            $roles = OpenRole::query()->visibleTo(null)
                ->orderBy('open_roles.id')
                ->limit(self::MAX_URLS - count($urls))
                ->get(['open_roles.id', 'open_roles.slug', 'open_roles.updated_at']);

            foreach ($roles as $role) {
                $urls[] = ['loc' => route('opportunities.show', $role->slug), 'lastmod' => $role->updated_at?->toAtomString()];
            }
        }

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
