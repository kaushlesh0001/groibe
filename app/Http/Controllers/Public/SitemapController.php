<?php

namespace App\Http\Controllers\Public;

use App\Enums\AgreementType;
use App\Enums\FeatureFlag;
use App\Http\Controllers\Controller;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Profile;
use App\Models\ProofOfWork;
use App\Models\Skill;
use App\Services\FeatureFlags\FeatureFlags;
use App\Services\Website\PublicCache;
use App\Services\Website\PublicSkillIndex;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Response;

/** `/sitemap.xml` and `/robots.txt` (spec 10.2, 10.4). Private profiles, private organizations, unpublished legal pages and unreviewed CMS pages are never listed. Cached for a few minutes and cleared when anything it lists changes. */
class SitemapController extends Controller
{
    private const MAX_URLS = 50000;

    public function sitemap(FeatureFlags $flags, Cache $cache): Response
    {
        // Kept for a few minutes and cleared as soon as anything it lists changes (PublicCache::watch).
        $xml = $cache->remember(PublicCache::SITEMAP_KEY, now()->addMinutes(PublicCache::minutes()), fn (): string => $this->build($flags));

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function build(FeatureFlags $flags): string
    {
        $urls = [['loc' => route('home'), 'lastmod' => null]];

        // The built-in pages that always exist. About is listed only once it has been published in Admin.
        foreach (['how-it-works', 'for-contributors', 'for-clients', 'faq', 'contact'] as $name) {
            $urls[] = ['loc' => route($name), 'lastmod' => null];
        }

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

            // Only verified, public organizations (spec 10.4). Private and pending ones never appear.
            $urls[] = ['loc' => route('organizations.index'), 'lastmod' => null];

            $organizations = Organization::query()->public()
                ->orderBy('organizations.id')
                ->limit(max(0, self::MAX_URLS - count($urls)))
                ->get(['organizations.id', 'organizations.slug', 'organizations.updated_at']);

            foreach ($organizations as $organization) {
                $urls[] = ['loc' => route('organizations.show', $organization->slug), 'lastmod' => $organization->updated_at?->toAtomString()];
            }
        }

        // Only published, public items whose project consent is anonymous or named. Consent `none` never appears (spec 9).
        if ($flags->enabled(FeatureFlag::ProofOfWorkPublic->value)) {
            $urls[] = ['loc' => route('work.index'), 'lastmod' => null];

            $items = ProofOfWork::query()->publiclyVisible()
                ->orderBy('proof_of_work.id')
                ->limit(self::MAX_URLS - count($urls))
                ->get(['proof_of_work.id', 'proof_of_work.slug', 'proof_of_work.updated_at']);

            foreach ($items as $item) {
                $urls[] = ['loc' => route('work.show', $item->slug), 'lastmod' => $item->updated_at?->toAtomString()];
            }
        }

        // Skill pages that have something to show; an empty skill page is kept out (it is also noindex).
        foreach (Skill::query()->active()->whereIn('skills.id', PublicSkillIndex::idsWithContent($flags))->orderBy('skills.id')->limit(max(0, self::MAX_URLS - count($urls)))->get(['skills.id', 'skills.slug', 'skills.updated_at']) as $skill) {
            $urls[] = ['loc' => route('skills.show', $skill->slug), 'lastmod' => $skill->updated_at?->toAtomString()];
        }

        // Terms and Privacy only once real wording is published (they answer 404 before that).
        foreach ([[AgreementType::Terms, 'terms'], [AgreementType::Privacy, 'privacy']] as [$type, $name]) {
            if (LegalPageController::published($type) !== null) {
                $urls[] = ['loc' => route($name), 'lastmod' => null];
            }
        }

        // CMS pages written in Admin: active and approved only. They are also the About page and any replacement of a built-in page.
        foreach (Page::query()->active()->orderBy('pages.id')->limit(max(0, self::MAX_URLS - count($urls)))->get(['pages.id', 'pages.slug', 'pages.updated_at']) as $page) {
            $loc = url($page->slug);

            if (! in_array($loc, array_column($urls, 'loc'), true)) {
                $urls[] = ['loc' => $loc, 'lastmod' => $page->updated_at?->toAtomString()];
            }
        }

        return view('public.sitemap', ['urls' => $urls])->render();
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
            'Disallow: /livewire',
            'Sitemap: '.route('sitemap'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
