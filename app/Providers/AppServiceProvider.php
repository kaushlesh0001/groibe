<?php

namespace App\Providers;

use App\Actions\Admin\StartImpersonation;
use App\Models\Category;
use App\Models\FeatureFlag;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\ProjectType;
use App\Models\Setting;
use App\Models\Skill;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\FeatureFlagPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ProfilePolicy;
use App\Policies\ProjectTypePolicy;
use App\Policies\SettingPolicy;
use App\Policies\TaxonomyPolicy;
use App\Policies\UserPolicy;
use App\Services\FeatureFlags\FeatureFlags;
use App\Services\Settings\Settings;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FeatureFlags::class);
        $this->app->singleton(Settings::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        // Spec 17.2: throttle invite redemption.
        RateLimiter::for('invite', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        Event::listen(Login::class, function (Login $event): void {
            if (! session()->has(StartImpersonation::SESSION_KEY) && $event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Profile::class, ProfilePolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Category::class, TaxonomyPolicy::class);
        Gate::policy(Skill::class, TaxonomyPolicy::class);
        Gate::policy(ProjectType::class, ProjectTypePolicy::class);
        Gate::policy(FeatureFlag::class, FeatureFlagPolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);

        // Spec 17.2: minimum 10 characters, compromised-password check where available.
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->uncompromised()
            : Password::min(10));
    }
}
