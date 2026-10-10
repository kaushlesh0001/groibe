<?php

use App\Actions\Applications\AcceptOffer;
use App\Actions\Applications\DeclineOffer;
use App\Actions\Applications\OfferApplication;
use App\Actions\Applications\RejectApplication;
use App\Actions\Applications\ShortlistApplication;
use App\Actions\Applications\SubmitApplication;
use App\Actions\Applications\WithdrawApplication;
use App\Actions\OpenRoles\CloseOpenRole;
use App\Enums\AdminRole;
use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Enums\FeatureFlag;
use App\Enums\OnboardingState;
use App\Enums\OpenRoleStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\OpenRole;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ApplicationUpdated;
use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->cm = adminUser(AdminRole::CommunityManager);
    $this->finance = adminUser(AdminRole::FinanceOps);
    $this->super = adminUser();
    $this->contributor = contributorIn(OnboardingState::Active);
    $this->role = OpenRole::factory()->published()->create(['positions_needed' => 1]);
});

function applyTo(OpenRole $role, User $user, array $data = []): Application
{
    return app(SubmitApplication::class)($role, $user, ['motivation' => 'I can do this well.', ...$data]);
}

function applicationIn(ApplicationStatus $status, ?OpenRole $role = null, ?User $user = null): Application
{
    return Application::factory()->status($status)->create([
        'open_role_id' => ($role ?? OpenRole::factory()->published()->create())->id,
        'user_id' => ($user ?? contributorIn(OnboardingState::Active))->id,
    ]);
}

describe('submit', function () {
    it('creates a submitted application, logs it and tells the people who review applications', function () {
        Notification::fake();

        $application = applyTo($this->role, $this->contributor, ['expected_amount' => 25000, 'availability_note' => 'Evenings']);

        expect($application->status)->toBe(ApplicationStatus::Submitted)
            ->and($application->user_id)->toBe($this->contributor->id)
            ->and($application->expected_amount)->toBe('25000.00')
            ->and(Activity::query()->where('log_name', 'application')->where('event', 'submitted')->count())->toBe(1);

        foreach ([$this->dm, $this->cm, $this->super] as $admin) {
            Notification::assertSentTo($admin, ApplicationUpdated::class, fn ($n) => $n->event === 'received' && $n->toAdmin);
        }
        Notification::assertNotSentTo($this->finance, ApplicationUpdated::class);
        Notification::assertNotSentTo($this->contributor, ApplicationUpdated::class);
    });

    it('blocks a second application to the same role', function () {
        applyTo($this->role, $this->contributor);

        expect(fn () => applyTo($this->role, $this->contributor))->toThrow(ActionBlocked::class, 'already applied')
            ->and(Application::query()->count())->toBe(1);
    });

    it('does not let non-active contributors, clients or admins apply', function (string $who) {
        $user = match ($who) {
            'pending' => contributorIn(OnboardingState::PendingReview),
            'suspended' => contributorIn(OnboardingState::Suspended),
            'client' => clientUser(),
            'admin' => $this->super,
        };

        expect(fn () => applyTo($this->role, $user))->toThrow(AuthorizationException::class)
            ->and(Application::query()->count())->toBe(0);
    })->with(['pending', 'suspended', 'client', 'admin']);

    it('keeps invite-only roles to the invited and drafts to nobody', function () {
        $invite = OpenRole::factory()->published()->inviteOnly()->create();
        $draft = OpenRole::factory()->create();

        expect(fn () => applyTo($invite, $this->contributor))->toThrow(AuthorizationException::class)
            ->and(fn () => applyTo($draft, $this->contributor))->toThrow(AuthorizationException::class);

        $invite->invitedUsers()->attach($this->contributor->id);

        expect(applyTo($invite, $this->contributor)->status)->toBe(ApplicationStatus::Submitted);
    });

    it('stops at the deadline and on closed, filled and archived roles', function () {
        $late = OpenRole::factory()->published()->create(['deadline' => now()->subDay()]);

        expect(fn () => applyTo($late, $this->contributor))->toThrow(ActionBlocked::class, 'no longer taking');

        foreach ([OpenRoleStatus::Closed, OpenRoleStatus::Filled, OpenRoleStatus::Archived] as $status) {
            $role = OpenRole::factory()->status($status)->create();
            expect(fn () => applyTo($role, $this->contributor))->toThrow(AuthorizationException::class);
        }
    });

    it('is switched off by the open_roles flag', function () {
        app(FeatureFlags::class)->set(FeatureFlag::OpenRoles, false);
        app(FeatureFlags::class)->flush();

        expect(fn () => applyTo($this->role, $this->contributor))->toThrow(ActionBlocked::class, 'not available')
            ->and(Application::query()->count())->toBe(0);
    });

    it('does not take proof-of-work ids until Pass 6, even if sent', function () {
        $application = applyTo($this->role, $this->contributor, ['proof_of_work_ids' => [1, 2]]);

        expect($application->proof_of_work_ids)->toBeNull();
    });
});

describe('reapply after withdrawal (SCR-019)', function () {
    it('reuses the withdrawn row with the new answers and keeps the old ones in the log', function () {
        $first = applyTo($this->role, $this->contributor, ['motivation' => 'First answer']);
        app(WithdrawApplication::class)($first, $this->contributor);

        $second = applyTo($this->role, $this->contributor, ['motivation' => 'Second answer']);

        expect($second->id)->toBe($first->id)
            ->and($second->status)->toBe(ApplicationStatus::Submitted)
            ->and($second->motivation)->toBe('Second answer')
            ->and(Application::query()->count())->toBe(1)
            ->and(Activity::query()->where('log_name', 'application')->where('event', 'submitted')->get()
                ->contains(fn ($a) => ($a->properties['earlier_motivation'] ?? null) === 'First answer'))->toBeTrue();
    });

    it('does not let a rejected, declined or accepted applicant apply again', function (ApplicationStatus $status) {
        applicationIn($status, $this->role, $this->contributor);

        expect(fn () => applyTo($this->role, $this->contributor))->toThrow(ActionBlocked::class, 'already applied');
    })->with([ApplicationStatus::Rejected, ApplicationStatus::Declined, ApplicationStatus::Accepted, ApplicationStatus::Offered]);
});

describe('admin review', function () {
    it('shortlists, offers and rejects as spec 6.5 says, telling the contributor each time', function () {
        Notification::fake();
        $application = applyTo($this->role, $this->contributor);

        $application = app(ShortlistApplication::class)($application, $this->cm, 'Strong fit');
        expect($application->status)->toBe(ApplicationStatus::Shortlisted)->and($application->shortlisted_at)->not->toBeNull();
        Notification::assertSentTo($this->contributor, ApplicationUpdated::class, fn ($n) => $n->event === 'shortlisted' && ! $n->toAdmin);

        $application = app(OfferApplication::class)($application, $this->dm);
        expect($application->status)->toBe(ApplicationStatus::Offered)
            ->and($application->decided_by)->toBe($this->dm->id)
            ->and($application->decided_at)->not->toBeNull();
        Notification::assertSentTo($this->contributor, ApplicationUpdated::class, fn ($n) => $n->event === 'offered');

        $other = applicationIn(ApplicationStatus::Submitted, $this->role);
        $other = app(RejectApplication::class)($other, $this->dm, 'Not a fit');
        expect($other->status)->toBe(ApplicationStatus::Rejected);
        Notification::assertSentTo($other->user, ApplicationUpdated::class, fn ($n) => $n->event === 'rejected');
    });

    it('never puts the admin note in a notification to the contributor', function () {
        Notification::fake();
        $application = applyTo($this->role, $this->contributor);
        app(RejectApplication::class)($application, $this->dm, 'Secret internal reason');

        Notification::assertSentTo($this->contributor, ApplicationUpdated::class, function ($n) {
            return ! str_contains(json_encode($n->toArray($this->contributor)), 'Secret internal reason');
        });
    });

    it('rejects every illegal move with IllegalTransition', function () {
        $submitted = applicationIn(ApplicationStatus::Submitted, $this->role);
        $rejected = applicationIn(ApplicationStatus::Rejected, $this->role);
        $accepted = applicationIn(ApplicationStatus::Accepted, $this->role);
        $withdrawn = applicationIn(ApplicationStatus::Withdrawn, $this->role);

        expect(fn () => app(OfferApplication::class)($submitted, $this->dm))->toThrow(IllegalTransition::class)
            ->and(fn () => app(ShortlistApplication::class)($rejected, $this->dm))->toThrow(IllegalTransition::class)
            ->and(fn () => app(ShortlistApplication::class)($withdrawn, $this->dm))->toThrow(IllegalTransition::class)
            ->and(fn () => app(RejectApplication::class)($accepted, $this->dm))->toThrow(IllegalTransition::class)
            ->and(fn () => app(RejectApplication::class)($rejected, $this->dm))->toThrow(IllegalTransition::class)
            ->and($submitted->fresh()->status)->toBe(ApplicationStatus::Submitted);
    });

    it('lets an admin reject an offered application', function () {
        $offered = applicationIn(ApplicationStatus::Offered, $this->role);

        expect(app(RejectApplication::class)($offered, $this->dm)->status)->toBe(ApplicationStatus::Rejected);
    });

    it('only shortlists and offers on a published role', function () {
        $application = applyTo($this->role, $this->contributor);
        app(CloseOpenRole::class)($this->role, $this->dm);

        expect(fn () => app(ShortlistApplication::class)($application, $this->dm))->toThrow(ActionBlocked::class);

        $shortlisted = applicationIn(ApplicationStatus::Shortlisted, $this->role);

        expect(fn () => app(OfferApplication::class)($shortlisted, $this->dm))->toThrow(ActionBlocked::class)
            ->and(app(RejectApplication::class)($shortlisted, $this->dm)->status)->toBe(ApplicationStatus::Rejected);
    });

    it('enforces the permission matrix', function () {
        $application = applicationIn(ApplicationStatus::Submitted, $this->role);
        $shortlisted = applicationIn(ApplicationStatus::Shortlisted, $this->role);

        foreach ([$this->finance, $this->contributor, clientUser()] as $denied) {
            expect(fn () => app(ShortlistApplication::class)($application, $denied))->toThrow(AuthorizationException::class)
                ->and(fn () => app(OfferApplication::class)($shortlisted, $denied))->toThrow(AuthorizationException::class)
                ->and(fn () => app(RejectApplication::class)($application, $denied))->toThrow(AuthorizationException::class);
        }

        expect($application->fresh()->status)->toBe(ApplicationStatus::Submitted);
    });
});

describe('contributor responses', function () {
    it('withdraws from submitted or shortlisted, and tells the admins', function () {
        Notification::fake();
        $application = applyTo($this->role, $this->contributor);
        app(ShortlistApplication::class)($application, $this->dm);

        $withdrawn = app(WithdrawApplication::class)($application, $this->contributor);

        expect($withdrawn->status)->toBe(ApplicationStatus::Withdrawn);
        Notification::assertSentTo($this->dm, ApplicationUpdated::class, fn ($n) => $n->event === 'withdrawn');
    });

    it('cannot withdraw once offered or decided, nor withdraw someone else\'s application', function () {
        foreach ([ApplicationStatus::Offered, ApplicationStatus::Accepted, ApplicationStatus::Rejected, ApplicationStatus::Declined, ApplicationStatus::Withdrawn] as $status) {
            $application = applicationIn($status, user: $this->contributor);
            expect(fn () => app(WithdrawApplication::class)($application, $this->contributor))->toThrow(AuthorizationException::class);
        }

        $mine = applyTo($this->role, $this->contributor);
        expect(fn () => app(WithdrawApplication::class)($mine, contributorIn(OnboardingState::Active)))->toThrow(AuthorizationException::class);
    });

    it('declines an offer', function () {
        Notification::fake();
        $offered = applicationIn(ApplicationStatus::Offered, $this->role, $this->contributor);

        expect(app(DeclineOffer::class)($offered, $this->contributor)->status)->toBe(ApplicationStatus::Declined);
        Notification::assertSentTo($this->dm, ApplicationUpdated::class, fn ($n) => $n->event === 'declined');
    });

    it('only responds to an offer addressed to them', function () {
        $shortlisted = applicationIn(ApplicationStatus::Shortlisted, $this->role, $this->contributor);
        $offered = applicationIn(ApplicationStatus::Offered, $this->role);

        expect(fn () => app(AcceptOffer::class)($shortlisted, $this->contributor))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeclineOffer::class)($shortlisted, $this->contributor))->toThrow(AuthorizationException::class)
            ->and(fn () => app(AcceptOffer::class)($offered, $this->contributor))->toThrow(AuthorizationException::class)
            ->and(fn () => app(AcceptOffer::class)($offered, $this->dm))->toThrow(AuthorizationException::class);
    });
});

describe('accepting an offer', function () {
    beforeEach(function () {
        $this->project = Project::factory()->status(ProjectStatus::Staffing)->create(['delivery_manager_id' => $this->dm->id]);
        $this->role = OpenRole::factory()->published()->create(['project_id' => $this->project->id, 'title' => 'Research analyst', 'positions_needed' => 1]);
        $this->offered = applicationIn(ApplicationStatus::Offered, $this->role, $this->contributor);
    });

    it('seats the contributor as an active assignee and fills the role', function () {
        Notification::fake();

        $accepted = app(AcceptOffer::class)($this->offered, $this->contributor);

        $assignment = Assignment::query()->where('project_id', $this->project->id)->sole();

        expect($accepted->status)->toBe(ApplicationStatus::Accepted)
            ->and($assignment->user_id)->toBe($this->contributor->id)
            ->and($assignment->status)->toBe(AssignmentStatus::Active)
            ->and($assignment->role_title)->toBe('Research analyst')
            ->and($assignment->accepted_at)->not->toBeNull()
            ->and($this->role->fresh()->status)->toBe(OpenRoleStatus::Filled)
            ->and($this->role->fresh()->closed_at)->not->toBeNull()
            ->and(Activity::query()->where('log_name', 'open_role')->where('event', 'filled')->count())->toBe(1);

        Notification::assertSentTo($this->dm, ApplicationUpdated::class, fn ($n) => $n->event === 'accepted');
    });

    it('leaves the role published until accepted offers reach the positions needed', function () {
        $this->role->update(['positions_needed' => 2]);

        app(AcceptOffer::class)($this->offered, $this->contributor);
        expect($this->role->fresh()->status)->toBe(OpenRoleStatus::Published);

        $second = contributorIn(OnboardingState::Active);
        $offer = applicationIn(ApplicationStatus::Offered, $this->role, $second);
        app(AcceptOffer::class)($offer, $second);

        expect($this->role->fresh()->status)->toBe(OpenRoleStatus::Filled)
            ->and(Assignment::query()->where('project_id', $this->project->id)->count())->toBe(2);
    });

    it('works on an active project too', function () {
        $this->project->update(['status' => ProjectStatus::Active]);

        app(AcceptOffer::class)($this->offered, $this->contributor);

        expect(Assignment::query()->where('project_id', $this->project->id)->sole()->status)->toBe(AssignmentStatus::Active);
    });

    it('creates no assignment for a community role with no project', function () {
        $community = OpenRole::factory()->published()->create(['project_id' => null]);
        $offer = applicationIn(ApplicationStatus::Offered, $community, $this->contributor);

        $accepted = app(AcceptOffer::class)($offer, $this->contributor);

        expect($accepted->status)->toBe(ApplicationStatus::Accepted)
            ->and(Assignment::query()->count())->toBe(0)
            ->and($community->fresh()->status)->toBe(OpenRoleStatus::Filled);
    });

    it('rolls everything back when the project cannot take the seat', function (ProjectStatus $status) {
        $this->project->update(['status' => $status]);

        expect(fn () => app(AcceptOffer::class)($this->offered, $this->contributor))->toThrow(ActionBlocked::class);

        expect($this->offered->fresh()->status)->toBe(ApplicationStatus::Offered)
            ->and(Assignment::query()->count())->toBe(0)
            ->and($this->role->fresh()->status)->toBe(OpenRoleStatus::Published);
    })->with([ProjectStatus::Draft, ProjectStatus::SowSent, ProjectStatus::OnHold, ProjectStatus::Cancelled, ProjectStatus::Closed]);

    it('rolls back when the role is no longer published', function (OpenRoleStatus $status) {
        $this->role->forceFill(['status' => $status])->save();

        expect(fn () => app(AcceptOffer::class)($this->offered, $this->contributor))->toThrow(ActionBlocked::class, 'no longer open');

        expect($this->offered->fresh()->status)->toBe(ApplicationStatus::Offered)->and(Assignment::query()->count())->toBe(0);
    })->with([OpenRoleStatus::Closed, OpenRoleStatus::Filled, OpenRoleStatus::Archived]);

    it('rolls back when the contributor already holds a seat on the project', function () {
        Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->contributor->id]);

        expect(fn () => app(AcceptOffer::class)($this->offered, $this->contributor))->toThrow(ActionBlocked::class, 'already holds a seat');

        expect($this->offered->fresh()->status)->toBe(ApplicationStatus::Offered);
    });

    it('is refused once the contributor is suspended', function () {
        $this->contributor->profile->forceFill(['onboarding_state' => OnboardingState::Suspended])->save();
        $this->contributor->forceFill(['status' => UserStatus::Suspended])->save();

        expect(fn () => app(AcceptOffer::class)($this->offered, $this->contributor->fresh()))->toThrow(AuthorizationException::class);
    });
});
