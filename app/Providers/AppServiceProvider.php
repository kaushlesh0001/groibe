<?php

namespace App\Providers;

use App\Actions\Admin\StartImpersonation;
use App\Http\Middleware\EnsureActiveContributor;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureUserRole;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\Assignment;
use App\Models\Brief;
use App\Models\Category;
use App\Models\CmsSection;
use App\Models\ContactMessage;
use App\Models\Deliverable;
use App\Models\EmailLog;
use App\Models\Faq;
use App\Models\FeaturedItem;
use App\Models\FeatureFlag;
use App\Models\Import;
use App\Models\InvoiceRef;
use App\Models\Message;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Payout;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\ProofOfWork;
use App\Models\Review;
use App\Models\Setting;
use App\Models\Skill;
use App\Models\Sow;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Testimonial;
use App\Models\Thread;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\AgreementAcceptancePolicy;
use App\Policies\AgreementTemplatePolicy;
use App\Policies\AssignmentPolicy;
use App\Policies\BriefPolicy;
use App\Policies\CmsContentPolicy;
use App\Policies\ContactMessagePolicy;
use App\Policies\DeliverablePolicy;
use App\Policies\EmailLogPolicy;
use App\Policies\FeatureFlagPolicy;
use App\Policies\ImportPolicy;
use App\Policies\InvoiceRefPolicy;
use App\Policies\MediaPolicy;
use App\Policies\MessagePolicy;
use App\Policies\MilestonePolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PayoutPolicy;
use App\Policies\ProfilePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProjectTypePolicy;
use App\Policies\ProofOfWorkPolicy;
use App\Policies\ReviewPolicy;
use App\Policies\SettingPolicy;
use App\Policies\SowPolicy;
use App\Policies\SubmissionPolicy;
use App\Policies\TaskCommentPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TaxonomyPolicy;
use App\Policies\ThreadPolicy;
use App\Policies\UserPolicy;
use App\Services\FeatureFlags\FeatureFlags;
use App\Services\Notifications\EmailLogger;
use App\Services\Projects\EloquentProjectTransitionGuards;
use App\Services\Projects\ProjectTransitionGuards;
use App\Services\Settings\Settings;
use App\Services\Website\PublicCache;
use App\Services\Website\PublicNavigation;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FeatureFlags::class);
        $this->app->singleton(Settings::class);

        // Cross-domain guards for the project state machine (spec 6.2).
        $this->app->bind(ProjectTransitionGuards::class, EloquentProjectTransitionGuards::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        // Livewire sends later actions and refreshes to its own endpoint and re-applies only a short list of the
        // page's middleware there. Role and active-contributor checks must be on that list (spec 4, 16), or a
        // contributor suspended while a page is open could keep using it.
        Livewire::addPersistentMiddleware([EnsureUserRole::class, EnsureActiveContributor::class, EnsureFeatureEnabled::class]);

        // Behind Cloudflare the visitor's address arrives in X-Forwarded-For, so rate limits and stored addresses need it.
        $trusted = trim((string) config('groibe.trusted_proxies'));

        if ($trusted !== '') {
            TrustProxies::at($trusted === '*' ? '*' : array_map('trim', explode(',', $trusted)));
        }

        // Spec 17.2: throttle invite redemption.
        RateLimiter::for('invite', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Spec 17.2: throttle the public contact form, per visitor address.
        RateLimiter::for('contact', fn (Request $request) => [
            Limit::perMinute(3)->by($request->ip()),
            Limit::perHour(12)->by($request->ip()),
        ]);

        // Spec 17.3: the homepage, its stats, the menu and the sitemap are cached and cleared when their data changes.
        $this->app->make(PublicCache::class)->watch();

        View::composer('components.layouts.public', fn ($view) => $view->with('nav', app(PublicNavigation::class)->links()));

        // Spec 7.8, 14: every email outcome lands in `email_log`.
        Event::listen(MessageSent::class, [EmailLogger::class, 'sent']);
        Event::listen(JobFailed::class, [EmailLogger::class, 'queuedNotificationFailed']);

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
        Gate::policy(AgreementTemplate::class, AgreementTemplatePolicy::class);
        Gate::policy(AgreementAcceptance::class, AgreementAcceptancePolicy::class);
        Gate::policy(Brief::class, BriefPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Assignment::class, AssignmentPolicy::class);
        Gate::policy(Milestone::class, MilestonePolicy::class);
        Gate::policy(Deliverable::class, DeliverablePolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(TaskComment::class, TaskCommentPolicy::class);
        Gate::policy(Submission::class, SubmissionPolicy::class);
        Gate::policy(Sow::class, SowPolicy::class);
        Gate::policy(ProofOfWork::class, ProofOfWorkPolicy::class);
        Gate::policy(Review::class, ReviewPolicy::class);
        Gate::policy(CmsSection::class, CmsContentPolicy::class);
        Gate::policy(FeaturedItem::class, CmsContentPolicy::class);
        Gate::policy(Testimonial::class, CmsContentPolicy::class);
        Gate::policy(Faq::class, CmsContentPolicy::class);
        Gate::policy(Page::class, CmsContentPolicy::class);
        Gate::policy(ContactMessage::class, ContactMessagePolicy::class);
        Gate::policy(EmailLog::class, EmailLogPolicy::class);
        Gate::policy(Payout::class, PayoutPolicy::class);
        Gate::policy(InvoiceRef::class, InvoiceRefPolicy::class);
        Gate::policy(Import::class, ImportPolicy::class);
        Gate::policy(Thread::class, ThreadPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);
        Gate::policy(Media::class, MediaPolicy::class);

        // Spec 17.2: minimum 10 characters, compromised-password check where available.
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->uncompromised()
            : Password::min(10));
    }
}
