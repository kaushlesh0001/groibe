<?php

namespace App\Services\Website;

use App\Enums\FaqAudience;
use App\Enums\FeaturedItemType;
use App\Enums\FeatureFlag;
use App\Enums\OnboardingState;
use App\Enums\OrganizationStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CmsSection;
use App\Models\Faq;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Testimonial;
use App\Services\FeatureFlags\FeatureFlags;
use App\Support\Website\HomepageDefaults;
use App\Support\Website\HomepageSections;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Builds the homepage from `cms_sections` (spec 10.1): the sections Admin has switched on, in the order Admin set,
 * each with its content over the built-in defaults and the live data it needs. A section that depends on a flag that
 * is off, or on data that does not exist, is left out, so the page never shows an empty heading. Only public records
 * are ever loaded. The result is cached for a few minutes and cleared when anything it shows changes (`flush`).
 */
class HomepageBuilder
{
    public const CACHE_KEY = 'website.homepage';

    public function __construct(private Cache $cache, private FeatureFlags $flags, private FeaturedItemResolver $featured) {}

    /** @return list<array{key: string, content: array<string, mixed>, data: array<string, mixed>}> */
    public function sections(): array
    {
        return $this->cache->remember(self::CACHE_KEY, now()->addMinutes((int) config('groibe.cache.public_minutes', 10)), fn (): array => $this->build());
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @return list<array{key: string, content: array<string, mixed>, data: array<string, mixed>}> */
    private function build(): array
    {
        $rows = CmsSection::query()->active()->ordered()->whereIn('key', HomepageSections::keys())->get();

        // A fresh install has no rows yet: show the built-in sections so the page is never blank.
        if (! CmsSection::query()->exists()) {
            $rows = collect(HomepageSections::keys())->map(fn (string $key): CmsSection => new CmsSection(['key' => $key, 'content' => []]));
        }

        $sections = [];

        foreach ($rows as $row) {
            $content = HomepageDefaults::merged($row->key, $row->content);
            $data = $this->data($row->key, $content);

            if ($data === null) {
                continue;
            }

            $sections[] = ['key' => $row->key, 'content' => $content, 'data' => $data];
        }

        return $sections;
    }

    /**
     * The data one section needs, or null when it has nothing to show and should be left out.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>|null
     */
    private function data(string $key, array $content): ?array
    {
        $limit = max(1, min(12, (int) ($content['limit'] ?? 6)));

        return match ($key) {
            'hero' => ['explore' => $this->flags->enabled(FeatureFlag::OpenRoles)],
            'ecosystem', 'how_it_works', 'final_cta' => [],
            'open_roles' => $this->flags->enabled(FeatureFlag::OpenRoles) ? $this->filled('roles', $this->roles($limit)) : null,
            'proof_of_work' => $this->flags->enabled(FeatureFlag::ProofOfWorkPublic)
                ? $this->filled('items', $this->featured->featured(FeaturedItemType::ProofOfWork, $limit, ['user.profile', 'skills', 'project.organization:id,name,industry']))
                : null,
            'community' => $this->flags->enabled(FeatureFlag::PublicDirectories)
                ? $this->filled('profiles', $this->featured->featured(FeaturedItemType::Contributor, $limit, ['user.skills']))
                : null,
            'organizations' => $this->flags->enabled(FeatureFlag::PublicDirectories) ? $this->filled('organizations', $this->organizations($limit)) : null,
            'stats' => $this->filled('tiles', $this->stats($content)),
            'testimonials' => $this->filled('testimonials', $this->testimonials($limit)),
            'faq' => $this->filled('groups', $this->faqs()),
            default => null,
        };
    }

    /** @return array<string, mixed>|null the data under one name, or null when it is empty */
    private function filled(string $name, Collection|array $items): ?array
    {
        return count($items) === 0 ? null : [$name => $items];
    }

    /** Featured picks first, in Admin's order, then roles flagged featured on the role itself. @return Collection<int, OpenRole> */
    private function roles(int $limit): Collection
    {
        $picked = $this->featured->featured(FeaturedItemType::OpenRole, $limit, ['skills', 'projectType']);

        $flagged = OpenRole::query()->visibleTo(null)->where('open_roles.is_featured', true)->with(['skills', 'projectType'])
            ->orderByDesc('open_roles.published_at')->limit($limit)->get();

        return $picked->concat($flagged)->unique('id')->take($limit)->values();
    }

    /** @return Collection<int, Model> organizations, as picked by Admin and as flagged featured */
    private function organizations(int $limit): Collection
    {
        $picked = $this->featured->featured(FeaturedItemType::Organization, $limit);
        $flagged = Organization::query()->public()->where('is_featured', true)->orderBy('name')->limit($limit)->get();

        return $picked->concat($flagged)->unique('id')->take($limit)->values();
    }

    /** Admin's picks if there are any, otherwise every active testimonial. @return Collection<int, Testimonial> */
    private function testimonials(int $limit): Collection
    {
        $picked = $this->featured->featured(FeaturedItemType::Testimonial, $limit);

        return $picked->isNotEmpty() ? $picked : Testimonial::query()->active()->ordered()->limit($limit)->get();
    }

    /** @return array<string, Collection<int, Faq>> audience value => questions, only audiences that have some */
    private function faqs(): array
    {
        $all = Faq::query()->active()->ordered()->get()->groupBy(fn (Faq $faq): string => $faq->audience->value);

        return collect(FaqAudience::cases())
            ->mapWithKeys(fn (FaqAudience $audience): array => [$audience->value => $all->get($audience->value, collect())])
            ->filter(fn (Collection $group): bool => $group->isNotEmpty())
            ->all();
    }

    /**
     * Live counts with Admin's override values (spec 10.1). An override replaces the count. A tile with no override and
     * a count of zero is left out, so the page never shows an empty "0".
     *
     * @param  array<string, mixed>  $content
     * @return list<array{label: string, value: int}>
     */
    private function stats(array $content): array
    {
        $live = [
            'contributors' => ['Contributors', fn (): int => Profile::query()
                ->where('onboarding_state', OnboardingState::Active->value)
                ->whereHas('user', fn ($user) => $user->where('role', UserRole::Contributor->value)->where('status', UserStatus::Active->value))
                ->count()],
            'organizations' => ['Organizations', fn (): int => Organization::query()->where('status', OrganizationStatus::Verified->value)->count()],
            'projects' => ['Projects delivered', fn (): int => Project::query()->whereIn('status', [ProjectStatus::Accepted->value, ProjectStatus::Closed->value])->count()],
        ];

        $tiles = [];

        foreach ($live as $name => [$label, $count]) {
            $override = $content["override_{$name}"] ?? null;
            $value = is_numeric($override) ? (int) $override : $count();

            if ($value > 0) {
                $tiles[] = ['label' => $label, 'value' => $value];
            }
        }

        return $tiles;
    }
}
