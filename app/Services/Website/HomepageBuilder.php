<?php

namespace App\Services\Website;

use App\Enums\CategoryType;
use App\Enums\FaqAudience;
use App\Enums\FeaturedItemType;
use App\Enums\FeatureFlag;
use App\Enums\OnboardingState;
use App\Enums\OrganizationStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Category;
use App\Models\CmsSection;
use App\Models\Faq;
use App\Models\FeaturedItem;
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

    /** The community row shows at most this many cards, and the whole section is left out below the minimum. */
    public const COMMUNITY_MAX = 12;

    public const COMMUNITY_MIN = 6;

    /** The hero rotates through at most this many skill categories. */
    public const HERO_CATEGORIES = 6;

    // The cached copy holds only what the homepage prints: no phone numbers, emails, contract values or GSTINs.
    private const ROLE_COLUMNS = ['open_roles.id', 'open_roles.slug', 'open_roles.title', 'open_roles.summary', 'open_roles.work_mode', 'open_roles.compensation_type', 'open_roles.duration_label', 'open_roles.deadline', 'open_roles.status', 'open_roles.visibility', 'open_roles.published_at'];

    private const ORGANIZATION_COLUMNS = ['organizations.id', 'organizations.slug', 'organizations.name', 'organizations.industry', 'organizations.city', 'organizations.state', 'organizations.is_public', 'organizations.status'];

    private const PROFILE_COLUMNS = ['profiles.id', 'profiles.user_id', 'profiles.slug', 'profiles.headline', 'profiles.city', 'profiles.state', 'profiles.photo_path', 'profiles.is_public', 'profiles.onboarding_state'];

    private const PROOF_COLUMNS = ['proof_of_work.id', 'proof_of_work.user_id', 'proof_of_work.project_id', 'proof_of_work.slug', 'proof_of_work.title', 'proof_of_work.summary', 'proof_of_work.role_title', 'proof_of_work.status', 'proof_of_work.visibility', 'proof_of_work.client_display', 'proof_of_work.published_at'];

    public function __construct(private Cache $cache, private FeatureFlags $flags, private FeaturedItemResolver $featured) {}

    /** @return list<array{key: string, content: array<string, mixed>, data: array<string, mixed>}> */
    public function sections(): array
    {
        return $this->cache->remember(self::CACHE_KEY, now()->addMinutes(PublicCache::minutes()), fn (): array => $this->build());
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
            'hero' => ['explore' => $this->flags->enabled(FeatureFlag::OpenRoles), 'categories' => $this->categories()],
            'ecosystem', 'how_it_works', 'final_cta' => [],
            'open_roles' => $this->flags->enabled(FeatureFlag::OpenRoles) ? $this->filled('roles', $this->roles($limit)) : null,
            'proof_of_work' => $this->flags->enabled(FeatureFlag::ProofOfWorkPublic)
                ? $this->filled('items', $this->featured->featured(
                    FeaturedItemType::ProofOfWork,
                    $limit,
                    ['user:id,name,role,status', 'user.profile:id,user_id,slug,is_public,onboarding_state', 'skills:id,name,slug', 'project:id,organization_id,showcase_consent', 'project.organization:id,name,industry'],
                    self::PROOF_COLUMNS,
                ))
                : null,
            'community' => $this->flags->enabled(FeatureFlag::PublicDirectories) ? $this->community() : null,
            'organizations' => $this->flags->enabled(FeatureFlag::PublicDirectories) ? $this->filled('organizations', $this->organizations($limit)) : null,
            'stats' => $this->filled('tiles', $this->stats($content)),
            'testimonials' => $this->filled('testimonials', $this->testimonials($limit)),
            'faq' => $this->filled('groups', $this->faqs()),
            default => null,
        };
    }

    /** @return list<string> names of the skill categories that have at least one active skill, in Admin's order */
    private function categories(): array
    {
        return Category::query()
            ->where('type', CategoryType::Skill->value)
            ->whereHas('skills', fn ($skills) => $skills->where('is_active', true))
            ->orderBy('sort')->orderBy('name')
            ->limit(self::HERO_CATEGORIES)
            ->pluck('name')
            ->all();
    }

    /**
     * The community row: publicly visible contributors only, through the same scope as the directory. Admin's featured
     * picks come first, then the newest others, up to the maximum. Under the minimum the section is left out. The cached
     * copy holds plain values (first name, headline, three skills, whether there is a photo), never an email or a phone.
     * Demo accounts never reach production.
     *
     * @return array<string, mixed>|null
     */
    private function community(): ?array
    {
        $with = ['user:id,name,email', 'user.skills:id,name,is_active'];
        $demoDomain = '@'.config('groibe.demo.email_domain');
        $base = fn () => Profile::query()->publiclyVisible()->select(self::PROFILE_COLUMNS)->with($with)
            ->when(app()->isProduction(), fn ($query) => $query->whereHas('user', fn ($user) => $user->where('email', 'not like', '%'.$demoDomain)));

        $pickedIds = FeaturedItem::query()->active()->ofType(FeaturedItemType::Contributor)->ordered()->pluck('item_id')->all();

        $picked = $pickedIds === []
            ? collect()
            : $base()->whereIn('profiles.user_id', $pickedIds)->get()->sortBy(fn (Profile $profile): int => (int) array_search($profile->user_id, $pickedIds, true))->take(self::COMMUNITY_MAX);

        $room = self::COMMUNITY_MAX - $picked->count();
        $rest = $room > 0
            ? $base()->whereNotIn('profiles.user_id', $pickedIds)->orderByDesc('profiles.id')->limit($room)->get()
            : collect();

        $cards = $picked->concat($rest)->map(fn (Profile $profile): array => [
            'slug' => (string) $profile->slug,
            'name' => $this->firstName($profile->user->name),
            'headline' => $profile->headline,
            'skills' => $profile->user->skills->where('is_active', true)->pluck('name')->take(3)->values()->all(),
            'photo' => $profile->photo_path !== null,
            'demo' => str_ends_with(strtolower($profile->user->email), $demoDomain),
        ])->values()->all();

        return count($cards) < self::COMMUNITY_MIN ? null : ['profiles' => $cards];
    }

    /** First name for a public card: the first word, skipping a leading title such as "Dr". */
    private function firstName(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [''];

        return in_array(rtrim(strtolower($words[0]), '.'), ['dr', 'mr', 'mrs', 'ms', 'prof'], true) && isset($words[1]) ? $words[1] : $words[0];
    }

    /** @return array<string, mixed>|null the data under one name, or null when it is empty */
    private function filled(string $name, Collection|array $items): ?array
    {
        return count($items) === 0 ? null : [$name => $items];
    }

    /** Featured picks first, in Admin's order, then roles flagged featured on the role itself. @return Collection<int, OpenRole> */
    private function roles(int $limit): Collection
    {
        $picked = $this->featured->featured(FeaturedItemType::OpenRole, $limit, ['skills:id,name,slug', 'projectType:id,name,slug'], self::ROLE_COLUMNS);

        $flagged = OpenRole::query()->visibleTo(null)->select(self::ROLE_COLUMNS)->where('open_roles.is_featured', true)->with(['skills:id,name,slug', 'projectType:id,name,slug'])
            ->orderByDesc('open_roles.published_at')->limit($limit)->get();

        return $picked->concat($flagged)->unique('id')->take($limit)->values();
    }

    /** @return Collection<int, Model> organizations, as picked by Admin and as flagged featured */
    private function organizations(int $limit): Collection
    {
        $picked = $this->featured->featured(FeaturedItemType::Organization, $limit, [], self::ORGANIZATION_COLUMNS);
        $flagged = Organization::query()->public()->select(self::ORGANIZATION_COLUMNS)->where('is_featured', true)->orderBy('name')->limit($limit)->get();

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
