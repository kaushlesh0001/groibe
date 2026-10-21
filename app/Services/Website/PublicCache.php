<?php

namespace App\Services\Website;

use App\Models\AgreementTemplate;
use App\Models\Category;
use App\Models\CmsSection;
use App\Models\Faq;
use App\Models\FeaturedItem;
use App\Models\FeatureFlag;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ProofOfWork;
use App\Models\Skill;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The public site's caches in one place (spec 17.3). The homepage, its stats, the menu and the sitemap are kept for a
 * few minutes (`groibe.cache.public_minutes`, 5 to 15) and cleared the moment something they show changes. File caches
 * have no tags, so there is one `flush()` for all of them, and `watch()` ties it to the models behind them.
 *
 * Only a change to a field the public can see clears the cache, so a sign-in or a note on a record does not.
 */
class PublicCache
{
    public const SITEMAP_KEY = 'website.sitemap';

    /**
     * Model => the attributes whose change matters, or null for "any change". Creating, deleting and restoring always count.
     *
     * @var array<class-string<Model>, list<string>|null>
     */
    public const WATCHED = [
        CmsSection::class => null,
        FeaturedItem::class => null,
        Testimonial::class => null,
        Faq::class => null,
        Page::class => null,
        AgreementTemplate::class => null,
        FeatureFlag::class => null,
        OpenRole::class => null,
        Organization::class => null,
        Category::class => ['name', 'type', 'sort'],
        Skill::class => ['name', 'slug', 'is_active'],
        Profile::class => ['slug', 'is_public', 'onboarding_state', 'headline', 'city', 'state', 'photo_path'],
        User::class => ['name', 'role', 'status'],
        ProofOfWork::class => ['status', 'visibility', 'slug', 'title', 'summary', 'project_id', 'client_display'],
        Project::class => ['status', 'showcase_consent'],
    ];

    public function __construct(private Cache $cache, private HomepageBuilder $homepage, private PublicNavigation $navigation) {}

    /** The minutes a public cache entry lives, held between 5 and 15 whatever the setting says. */
    public static function minutes(): int
    {
        return max(5, min(15, (int) config('groibe.cache.public_minutes', 10)));
    }

    public function flush(): void
    {
        $this->homepage->flush();
        $this->navigation->flush();
        $this->cache->forget(self::SITEMAP_KEY);
    }

    /** Clear the caches whenever a watched model changes in a way the public can see. */
    public function watch(): void
    {
        foreach (self::WATCHED as $class => $attributes) {
            $class::created(fn () => $this->flush());

            $class::updated(function (Model $model) use ($attributes): void {
                if ($attributes === null ? $model->wasChanged() : $model->wasChanged($attributes)) {
                    $this->flush();
                }
            });

            $class::deleted(fn () => $this->flush());

            if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                $class::restored(fn () => $this->flush());
            }
        }
    }
}
