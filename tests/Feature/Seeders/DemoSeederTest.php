<?php

use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\ProofStatus;
use App\Enums\ProofVisibility;
use App\Enums\ReviewDirection;
use App\Enums\ReviewStatus;
use App\Enums\ShowcaseConsent;
use App\Enums\UserRole;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\Application;
use App\Models\Brief;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ProofOfWork;
use App\Models\ReputationFact;
use App\Models\Review;
use App\Models\User;
use App\Services\Agreements\AgreementGate;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

it('refuses to run in production and writes nothing', function () {
    app()->detectEnvironment(fn () => 'production');

    // --force skips Laravel's own production prompt, so this is the seeder's guard on its own.
    expect(fn () => Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]))
        ->toThrow(RuntimeException::class, 'Refusing to run');

    expect(User::query()->count())->toBe(0)
        ->and(Organization::query()->count())->toBe(0);
});

it('refuses any environment other than local, staging and testing', function () {
    app()->detectEnvironment(fn () => 'qa-live');

    expect(fn () => Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]))
        ->toThrow(RuntimeException::class);
});

it('runs in staging', function () {
    app()->detectEnvironment(fn () => 'staging');

    $this->seed(DemoSeeder::class);

    expect(User::query()->where('role', UserRole::Contributor)->count())->toBe(12);
});

it('creates 12 contributors in mixed states', function () {
    $this->seed(DemoSeeder::class);

    $profiles = Profile::query()->get();
    $count = fn (OnboardingState $state, ?bool $public = null) => $profiles
        ->filter(fn (Profile $p) => $p->onboarding_state === $state && ($public === null || $p->is_public === $public))->count();

    expect($profiles)->toHaveCount(12)
        ->and($count(OnboardingState::PendingReview))->toBe(3)
        ->and($count(OnboardingState::Active, true))->toBe(4)
        ->and($count(OnboardingState::Active, false))->toBe(3)
        ->and($count(OnboardingState::Suspended))->toBe(2)
        ->and(Profile::query()->publiclyVisible()->count())->toBe(4);
});

it('keeps user status in step with onboarding so only active contributors can work', function () {
    $this->seed(DemoSeeder::class);

    $users = User::query()->where('role', UserRole::Contributor)->with('profile')->get();

    expect($users->filter->isActiveContributor())->toHaveCount(7)
        ->and($users->every(fn (User $u) => $u->email_verified_at !== null))->toBeTrue();
});

it('records the Member Agreement for every contributor', function () {
    $this->seed(DemoSeeder::class);

    expect(AgreementAcceptance::query()->whereIn('user_id', User::query()->where('role', UserRole::Contributor)->select('id'))->distinct()->count('user_id'))->toBe(12);
});

it('creates 4 organizations with client users and the Client MSA gate in the right state', function () {
    $this->seed(DemoSeeder::class);

    $gate = app(AgreementGate::class);
    $organizations = Organization::query()->with('users')->get();

    expect($organizations)->toHaveCount(4)
        ->and(User::query()->where('role', UserRole::Client)->count())->toBe(7)
        ->and($organizations->every(fn (Organization $o) => $o->users->contains(fn (User $u) => $u->pivot->org_role === OrgRole::Owner)))->toBeTrue()
        ->and($organizations->where('slug', 'lumen-retail-demo')->first()->isVerified())->toBeFalse()
        ->and($gate->hasAcceptedClientMsa($organizations->firstWhere('slug', 'kestrel-foods-demo')))->toBeTrue()
        ->and($gate->hasAcceptedClientMsa($organizations->firstWhere('slug', 'lumen-retail-demo')))->toBeFalse();
});

it('creates briefs and projects across states, with the delivery chain wired up', function () {
    $this->seed(DemoSeeder::class);

    $projects = Project::query()->with(['sows', 'assignments', 'milestones.tasks'])->get();
    $active = $projects->firstWhere('status', ProjectStatus::Active);

    expect(Brief::query()->count())->toBe(6)
        ->and($projects)->toHaveCount(8)
        ->and($projects->pluck('status')->map->value->all())->toEqualCanonicalizing(['active', 'sow_sent', 'staffing', 'draft', 'accepted', 'accepted', 'accepted', 'closed'])
        ->and($projects->pluck('code')->unique())->toHaveCount(8)
        ->and($active->assignments)->toHaveCount(1)
        ->and($active->milestones)->toHaveCount(2)
        ->and($active->milestones->flatMap->tasks)->toHaveCount(3)
        ->and(Brief::query()->whereNotNull('converted_project_id')->count())->toBe(2);
});

it('uses fake names and a reserved email domain only, with one known password', function () {
    $this->seed(DemoSeeder::class);

    $emails = User::query()->pluck('email');

    expect($emails->every(fn (string $e) => str_ends_with($e, '@'.DemoSeeder::EMAIL_DOMAIN)))->toBeTrue()
        ->and(Hash::check(DemoSeeder::PASSWORD, User::query()->where('email', 'aditi.sharma@demo.groibe.test')->value('password')))->toBeTrue()
        ->and(Hash::check(DemoSeeder::PASSWORD, User::query()->where('email', 'rhea.kapoor@demo.groibe.test')->value('password')))->toBeTrue();
});

it('is safe to run twice: no duplicates and no rewritten records', function () {
    $this->seed(DemoSeeder::class);
    $snapshot = fn () => [
        User::query()->count(), Profile::query()->count(), Organization::query()->count(), Brief::query()->count(),
        Project::query()->count(), AgreementAcceptance::query()->count(), DB::table('skill_user')->count(),
        DB::table('organization_users')->count(), DB::table('assignments')->count(), DB::table('tasks')->count(),
        DB::table('milestones')->count(), DB::table('sows')->count(), DB::table('agreement_templates')->count(),
        DB::table('open_roles')->count(), DB::table('open_role_skills')->count(), DB::table('open_role_invites')->count(), DB::table('applications')->count(),
        DB::table('proof_of_work')->count(), DB::table('proof_of_work_skill')->count(), DB::table('reviews')->count(),
        DB::table('reputation_facts')->count(), DB::table('organization_stats')->count(),
    ];
    $before = $snapshot();
    $hash = User::query()->where('email', 'aditi.sharma@demo.groibe.test')->value('password');

    $this->seed(DemoSeeder::class);

    expect($snapshot())->toBe($before)
        ->and(User::query()->where('email', 'aditi.sharma@demo.groibe.test')->value('password'))->toBe($hash);
});

it('leaves existing data alone and does not wipe the database', function () {
    $admin = adminUser();
    $organization = Organization::factory()->verified()->create(['name' => 'Real Client']);
    $contributor = contributorIn(OnboardingState::PendingReview);
    $template = AgreementTemplate::factory()->create(['title' => 'Founder wording', 'body_html' => '<p>Real legal text</p>']);

    $this->artisan('db:seed', ['--class' => DemoSeeder::class])->assertSuccessful();

    expect($admin->fresh())->not->toBeNull()
        ->and($organization->fresh()->name)->toBe('Real Client')
        ->and($contributor->fresh()->profile->onboarding_state)->toBe(OnboardingState::PendingReview)
        ->and($template->fresh()->body_html)->toBe('<p>Real legal text</p>')
        ->and(User::query()->where('role', UserRole::Contributor)->count())->toBe(13);
});

it('sends no mail, notifications or queued jobs while seeding', function () {
    Mail::fake();
    Notification::fake();
    Queue::fake();

    $this->seed(DemoSeeder::class);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

it('creates open roles in every visibility and state, with applications and an invite list', function () {
    $this->seed(DemoSeeder::class);

    $roles = OpenRole::query()->get();

    expect($roles)->toHaveCount(4)
        ->and($roles->pluck('status')->map->value->all())->toEqualCanonicalizing(['published', 'published', 'published', 'draft'])
        ->and(OpenRole::query()->visibleTo(null)->pluck('title')->all())->toBe(['Survey analyst'])
        ->and(Application::query()->count())->toBe(4)
        ->and(DB::table('open_role_invites')->count())->toBe(1)
        ->and(OpenRole::query()->where('slug', 'demo-dashboard-support')->value('project_id'))->not->toBeNull();
});

it('creates three accepted projects and one closed project across the three showcase consent values', function () {
    $this->seed(DemoSeeder::class);

    $accepted = Project::query()->where('status', ProjectStatus::Accepted)->get();
    $closed = Project::query()->where('status', ProjectStatus::Closed)->get();

    expect($accepted)->toHaveCount(3)
        ->and($accepted->pluck('showcase_consent')->map->value->all())->toEqualCanonicalizing(['none', 'anonymous', 'named'])
        ->and($closed)->toHaveCount(1)
        ->and($closed->first()->showcase_consent)->toBe(ShowcaseConsent::Named)
        ->and($closed->first()->closed_at)->not->toBeNull()
        ->and(Project::query()->whereIn('status', [ProjectStatus::Accepted, ProjectStatus::Closed])->get()->every(
            fn (Project $project) => $project->assignments()->where('status', 'completed')->exists() && $project->sows()->where('status', 'accepted')->exists(),
        ))->toBeTrue();
});

it('creates published and draft proof of work, and only consented public items are visible to the public', function () {
    $this->seed(DemoSeeder::class);

    $proofs = ProofOfWork::query()->get();

    expect($proofs)->toHaveCount(3)
        ->and($proofs->where('status', ProofStatus::Published))->toHaveCount(2)
        ->and($proofs->where('status', ProofStatus::Draft))->toHaveCount(1)
        ->and($proofs->where('status', ProofStatus::Published)->pluck('visibility')->all())->toEqualCanonicalizing([ProofVisibility::Public, ProofVisibility::Members])
        ->and(ProofOfWork::query()->publiclyVisible()->count())->toBe(1)
        ->and(ProofOfWork::query()->publiclyVisible()->first()->clientLabel())->toBe('Kestrel Foods (Demo)');
});

it('creates published and pending reviews and fills reputation from them', function () {
    $this->seed(DemoSeeder::class);

    $published = Review::query()->where('status', ReviewStatus::Published)->get();

    expect(Review::query()->count())->toBe(5)
        ->and($published)->toHaveCount(4)
        ->and(Review::query()->where('status', ReviewStatus::PendingModeration)->count())->toBe(1)
        ->and($published->where('direction', ReviewDirection::ContributorToClient))->toHaveCount(1)
        ->and(ReputationFact::query()->where('user_id', User::query()->where('email', 'aditi.sharma@demo.groibe.test')->value('id'))->first())
        ->reviews_count->toBe(2)
        ->projects_completed->toBe(2);
});
