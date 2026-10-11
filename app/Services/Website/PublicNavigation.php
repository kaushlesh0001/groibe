<?php

namespace App\Services\Website;

use App\Enums\AgreementType;
use App\Enums\FeatureFlag;
use App\Http\Controllers\Public\LegalPageController;
use App\Models\Page;
use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The links in the public header and footer. A link appears only when its page exists for visitors: a section whose
 * flag is off, an About page nobody has published, or Terms and Privacy still on placeholder text are left out, so
 * the menu never points at a 404. Cached with the homepage and cleared with it.
 */
class PublicNavigation
{
    public const CACHE_KEY = 'website.navigation';

    public function __construct(private Cache $cache, private FeatureFlags $flags) {}

    /** @return array{header: list<array{label: string, url: string}>, footer: list<array{label: string, url: string}>} */
    public function links(): array
    {
        return $this->cache->remember(self::CACHE_KEY, now()->addMinutes((int) config('groibe.cache.public_minutes', 10)), fn (): array => $this->build());
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @return array{header: list<array{label: string, url: string}>, footer: list<array{label: string, url: string}>} */
    private function build(): array
    {
        $header = [];

        if ($this->flags->enabled(FeatureFlag::OpenRoles)) {
            $header[] = ['label' => 'Opportunities', 'url' => route('opportunities.index')];
        }

        if ($this->flags->enabled(FeatureFlag::PublicDirectories)) {
            $header[] = ['label' => 'Contributors', 'url' => route('contributors.index')];
            $header[] = ['label' => 'Organizations', 'url' => route('organizations.index')];
        }

        if ($this->flags->enabled(FeatureFlag::ProofOfWorkPublic)) {
            $header[] = ['label' => 'Our work', 'url' => route('work.index')];
        }

        $footer = [
            ['label' => 'How it works', 'url' => route('how-it-works')],
            ['label' => 'For contributors', 'url' => route('for-contributors')],
            ['label' => 'For clients', 'url' => route('for-clients')],
        ];

        if (Page::query()->active()->where('slug', 'about')->exists()) {
            $footer[] = ['label' => 'About', 'url' => route('about')];
        }

        $footer[] = ['label' => 'FAQ', 'url' => route('faq')];
        $footer[] = ['label' => 'Contact', 'url' => route('contact')];

        if (LegalPageController::published(AgreementType::Terms) !== null) {
            $footer[] = ['label' => 'Terms', 'url' => route('terms')];
        }

        if (LegalPageController::published(AgreementType::Privacy) !== null) {
            $footer[] = ['label' => 'Privacy', 'url' => route('privacy')];
        }

        return ['header' => $header, 'footer' => $footer];
    }
}
