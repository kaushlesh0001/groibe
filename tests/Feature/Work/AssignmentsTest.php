<?php

use App\Actions\Assignments\AcceptAssignment;
use App\Actions\Assignments\DeclineAssignment;
use App\Actions\Assignments\InviteContributor;
use App\Actions\Assignments\RemoveAssignment;
use App\Actions\Projects\AcceptProject;
use App\Actions\Projects\ActivateProject;
use App\Enums\AdminRole;
use App\Enums\AssignmentStatus;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\ProjectStatus;
use App\Enums\RateType;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\RelationManagers\AssignmentsRelationManager;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Notifications\AssignmentInvited;
use App\Notifications\AssignmentRemoved;
use App\Notifications\AssignmentResponded;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Staffing)->create(['delivery_manager_id' => $this->dm->id]);
    $this->contributor = contributorIn(OnboardingState::Active);
});

function invite(Project $project, $contributor, $actor, array $data = []): Assignment
{
    return app(InviteContributor::class)($project, $contributor, $actor, ['role_title' => 'Analyst', ...$data]);
}

describe('invite', function () {
    it('creates an invited seat, logs it and notifies the contributor', function () {
        Notification::fake();

        $assignment = invite($this->project, $this->contributor, $this->dm, ['rate_type' => RateType::Hourly, 'agreed_amount' => 900]);

        expect($assignment->status)->toBe(AssignmentStatus::Invited)
            ->and($assignment->project_id)->toBe($this->project->id)
            ->and($assignment->user_id)->toBe($this->contributor->id)
            ->and($assignment->rate_type)->toBe(RateType::Hourly)
            ->and($assignment->currency)->toBe('INR')
            ->and($assignment->invited_at)->not->toBeNull()
            ->and(Activity::query()->where('log_name', 'assignment')->where('event', 'invited')->count())->toBe(1);

        Notification::assertSentTo($this->contributor, AssignmentInvited::class);
    });

    it('is allowed while the project is active too', function () {
        $this->project->forceFill(['status' => ProjectStatus::Active])->save();

        expect(invite($this->project, $this->contributor, $this->dm)->status)->toBe(AssignmentStatus::Invited);
    });

    it('is refused outside staffing and active', function (ProjectStatus $status) {
        $this->project->forceFill(['status' => $status])->save();

        expect(fn () => invite($this->project, $this->contributor, $this->dm))->toThrow(ActionBlocked::class, 'staffing or active');
        expect(Assignment::query()->count())->toBe(0);
    })->with([ProjectStatus::Draft, ProjectStatus::SowSent, ProjectStatus::SowAccepted, ProjectStatus::OnHold, ProjectStatus::InQa, ProjectStatus::Accepted, ProjectStatus::Closed, ProjectStatus::Cancelled]);

    it('only seats active contributors', function (string $kind) {
        $person = match ($kind) {
            'pending_review' => contributorIn(OnboardingState::PendingReview),
            'suspended' => contributorIn(OnboardingState::Suspended),
            'rejected' => contributorIn(OnboardingState::Rejected),
            'client' => clientUser(),
            'admin' => adminUser(),
        };

        expect(fn () => invite($this->project, $person, $this->dm))->toThrow(ActionBlocked::class, 'active contributors');
        expect(Assignment::query()->count())->toBe(0);
    })->with(['pending_review', 'suspended', 'rejected', 'client', 'admin']);

    it('refuses a second open seat for the same contributor but allows a re-invite after a decline', function () {
        $first = invite($this->project, $this->contributor, $this->dm);

        expect(fn () => invite($this->project, $this->contributor, $this->dm))->toThrow(ActionBlocked::class, 'already holds a seat');

        app(DeclineAssignment::class)($first, $this->contributor);

        expect(invite($this->project, $this->contributor, $this->dm)->status)->toBe(AssignmentStatus::Invited)
            ->and(Assignment::query()->count())->toBe(2);
    });

    it('lets the same contributor sit on several projects', function () {
        $other = Project::factory()->status(ProjectStatus::Staffing)->create();

        invite($this->project, $this->contributor, $this->dm);

        expect(invite($other, $this->contributor, $this->dm)->project_id)->toBe($other->id);
    });

    it('is limited to admins with project write access', function (string $who) {
        $actor = match ($who) {
            'community_manager' => adminUser(AdminRole::CommunityManager),
            'finance_ops' => adminUser(AdminRole::FinanceOps),
            'contributor' => contributorIn(OnboardingState::Active),
            'client' => clientUser($this->project->organization),
        };

        expect(fn () => invite($this->project, $this->contributor, $actor))->toThrow(AuthorizationException::class);
        expect(Assignment::query()->count())->toBe(0);
    })->with(['community_manager', 'finance_ops', 'contributor', 'client']);
});

describe('accept and decline', function () {
    beforeEach(function () {
        $this->assignment = Assignment::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->contributor->id]);
    });

    it('activates the seat and tells the delivery manager', function () {
        Notification::fake();

        $assignment = app(AcceptAssignment::class)($this->assignment, $this->contributor);

        expect($assignment->status)->toBe(AssignmentStatus::Active)
            ->and($assignment->accepted_at)->not->toBeNull()
            ->and(Activity::query()->where('log_name', 'assignment')->where('event', 'active')->count())->toBe(1);

        Notification::assertSentTo($this->dm, AssignmentResponded::class, fn ($n) => $n->accepted === true);
    });

    it('records a decline and tells the delivery manager', function () {
        Notification::fake();

        $assignment = app(DeclineAssignment::class)($this->assignment, $this->contributor);

        expect($assignment->status)->toBe(AssignmentStatus::Declined)
            ->and($assignment->accepted_at)->toBeNull();

        Notification::assertSentTo($this->dm, AssignmentResponded::class, fn ($n) => $n->accepted === false);
    });

    it('cannot be answered by anyone but the invited contributor', function (string $who) {
        $actor = match ($who) {
            'other_contributor' => contributorIn(OnboardingState::Active),
            'admin' => $this->dm,
            'client' => clientUser($this->project->organization),
        };

        expect(fn () => app(AcceptAssignment::class)($this->assignment, $actor))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeclineAssignment::class)($this->assignment, $actor))->toThrow(AuthorizationException::class);

        expect($this->assignment->refresh()->status)->toBe(AssignmentStatus::Invited);
    })->with(['other_contributor', 'admin', 'client']);

    it('cannot be answered twice', function () {
        app(AcceptAssignment::class)($this->assignment, $this->contributor);

        expect(fn () => app(AcceptAssignment::class)($this->assignment, $this->contributor))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeclineAssignment::class)($this->assignment, $this->contributor))->toThrow(AuthorizationException::class);
    });

    it('cannot be accepted once the project has moved on', function () {
        $this->project->forceFill(['status' => ProjectStatus::OnHold])->save();

        expect(fn () => app(AcceptAssignment::class)($this->assignment, $this->contributor))->toThrow(ActionBlocked::class);
        expect($this->assignment->refresh()->status)->toBe(AssignmentStatus::Invited);
    });

    it('is denied to a contributor who has since been suspended', function () {
        $this->contributor->forceFill(['status' => UserStatus::Suspended])->save();
        $this->contributor->profile->forceFill(['onboarding_state' => OnboardingState::Suspended])->save();

        expect(fn () => app(AcceptAssignment::class)($this->assignment, $this->contributor->fresh()))->toThrow(AuthorizationException::class);
    });
});

describe('remove', function () {
    beforeEach(function () {
        $this->assignment = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->contributor->id]);
        $this->milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $this->open = collect([TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::Submitted, TaskStatus::ChangesRequested])
            ->map(fn (TaskStatus $s) => Task::factory()->status($s)->create(['milestone_id' => $this->milestone->id, 'project_id' => $this->project->id, 'assignment_id' => $this->assignment->id]));
        $this->approved = Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $this->milestone->id, 'project_id' => $this->project->id, 'assignment_id' => $this->assignment->id]);
    });

    it('removes the seat, returns unfinished tasks to unassigned todo and keeps approved work', function () {
        Notification::fake();

        $assignment = app(RemoveAssignment::class)($this->assignment, $this->dm, 'Unresponsive for a week');

        expect($assignment->status)->toBe(AssignmentStatus::Removed)
            ->and($assignment->removed_reason)->toBe('Unresponsive for a week');

        foreach ($this->open as $task) {
            $task->refresh();
            expect($task->assignment_id)->toBeNull()->and($task->status)->toBe(TaskStatus::Todo);
        }

        expect($this->approved->refresh()->assignment_id)->toBe($this->assignment->id)
            ->and($this->approved->status)->toBe(TaskStatus::Approved)
            ->and(Activity::query()->where('log_name', 'task')->where('event', 'unassigned')->count())->toBe(4);

        Notification::assertSentTo($this->dm, AssignmentRemoved::class);
        Notification::assertSentTo($this->contributor, AssignmentRemoved::class);
    });

    it('pulls a milestone back out of QA when a submitted task returns to todo (SCR-015)', function () {
        $inQa = Milestone::factory()->status(MilestoneStatus::InQa)->create(['project_id' => $this->project->id]);
        Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $inQa->id, 'assignment_id' => $this->assignment->id]);
        Task::factory()->status(TaskStatus::Submitted)->create(['milestone_id' => $inQa->id, 'assignment_id' => $this->assignment->id]);
        $keepsQa = Milestone::factory()->status(MilestoneStatus::InQa)->create(['project_id' => $this->project->id]);
        $other = Assignment::factory()->active()->create(['project_id' => $this->project->id]);
        Task::factory()->status(TaskStatus::Submitted)->create(['milestone_id' => $keepsQa->id, 'assignment_id' => $other->id]);
        $shared = Milestone::factory()->status(MilestoneStatus::Shared)->create(['project_id' => $this->project->id]);
        Task::factory()->status(TaskStatus::Submitted)->create(['milestone_id' => $shared->id, 'assignment_id' => $this->assignment->id]);

        app(RemoveAssignment::class)($this->assignment, $this->dm, 'Left the team');

        expect($inQa->refresh()->status)->toBe(MilestoneStatus::InProgress)
            ->and($keepsQa->refresh()->status)->toBe(MilestoneStatus::InQa)
            ->and($shared->refresh()->status)->toBe(MilestoneStatus::Shared);
    });

    it('keeps the internal reason out of the contributor notification', function () {
        $assignment = app(RemoveAssignment::class)($this->assignment, $this->dm, 'Quality issues on task 3');
        $notification = new AssignmentRemoved($assignment->refresh(), $this->project, $this->contributor->name, 4);

        expect(json_encode($notification->toArray($this->contributor)))->not->toContain('Quality issues')
            ->and(json_encode($notification->toArray($this->dm)))->toContain('Quality issues')
            ->and($notification->toMail($this->contributor)->render()->toHtml())->not->toContain('Quality issues');
    });

    it('requires a reason', function () {
        expect(fn () => app(RemoveAssignment::class)($this->assignment, $this->dm, ' '))->toThrow(ActionBlocked::class, 'reason');
        expect($this->assignment->refresh()->status)->toBe(AssignmentStatus::Active);
    });

    it('only removes active seats', function (AssignmentStatus $status) {
        $assignment = Assignment::factory()->status($status)->create(['project_id' => $this->project->id]);

        expect(fn () => app(RemoveAssignment::class)($assignment, $this->dm, 'x'))->toThrow(IllegalTransition::class);
    })->with([AssignmentStatus::Invited, AssignmentStatus::Declined, AssignmentStatus::Completed, AssignmentStatus::Removed]);

    it('is limited to admins with project write access', function (string $who) {
        $actor = match ($who) {
            'finance_ops' => adminUser(AdminRole::FinanceOps),
            'the_contributor' => $this->contributor,
            'client' => clientUser($this->project->organization),
        };

        expect(fn () => app(RemoveAssignment::class)($this->assignment, $actor, 'x'))->toThrow(AuthorizationException::class);
        expect($this->assignment->refresh()->status)->toBe(AssignmentStatus::Active);
    })->with(['finance_ops', 'the_contributor', 'client']);
});

describe('project guards', function () {
    it('needs an active assignment to go from staffing to active', function () {
        expect(fn () => app(ActivateProject::class)($this->project, $this->dm))->toThrow(ActionBlocked::class, 'active assignment');

        foreach ([AssignmentStatus::Invited, AssignmentStatus::Declined, AssignmentStatus::Removed] as $status) {
            Assignment::factory()->status($status)->create(['project_id' => $this->project->id]);
        }
        expect(fn () => app(ActivateProject::class)($this->project, $this->dm))->toThrow(ActionBlocked::class);

        $seat = invite($this->project, contributorIn(OnboardingState::Active), $this->dm);
        app(AcceptAssignment::class)($seat, $seat->user);

        expect(app(ActivateProject::class)($this->project, $this->dm)->status)->toBe(ProjectStatus::Active);
    });

    it('counts only assignments of this project', function () {
        Assignment::factory()->active()->create();

        expect(fn () => app(ActivateProject::class)($this->project, $this->dm))->toThrow(ActionBlocked::class);
    });

    it('completes active assignments when the project is accepted and leaves the rest alone', function () {
        $this->project->forceFill(['status' => ProjectStatus::Delivered])->save();
        Milestone::factory()->status(MilestoneStatus::ClientAccepted)->create(['project_id' => $this->project->id]);

        $active = Assignment::factory()->active()->create(['project_id' => $this->project->id]);
        $removed = Assignment::factory()->status(AssignmentStatus::Removed)->create(['project_id' => $this->project->id]);
        $declined = Assignment::factory()->status(AssignmentStatus::Declined)->create(['project_id' => $this->project->id]);
        $elsewhere = Assignment::factory()->active()->create();

        app(AcceptProject::class)($this->project, $this->dm);

        expect($active->refresh()->status)->toBe(AssignmentStatus::Completed)
            ->and($active->completed_at)->not->toBeNull()
            ->and($removed->refresh()->status)->toBe(AssignmentStatus::Removed)
            ->and($declined->refresh()->status)->toBe(AssignmentStatus::Declined)
            ->and($elsewhere->refresh()->status)->toBe(AssignmentStatus::Active);
    });

    it('needs every milestone client-accepted, and at least one, to accept the project', function () {
        $this->project->forceFill(['status' => ProjectStatus::Delivered])->save();

        expect(fn () => app(AcceptProject::class)($this->project, $this->dm))->toThrow(ActionBlocked::class, 'milestones');

        Milestone::factory()->status(MilestoneStatus::ClientAccepted)->create(['project_id' => $this->project->id]);
        Milestone::factory()->status(MilestoneStatus::Shared)->create(['project_id' => $this->project->id]);

        expect(fn () => app(AcceptProject::class)($this->project, $this->dm))->toThrow(ActionBlocked::class, 'milestones');

        Milestone::query()->update(['status' => MilestoneStatus::ClientAccepted]);

        expect(app(AcceptProject::class)($this->project, $this->dm)->status)->toBe(ProjectStatus::Accepted);
    });

    it('rolls assignments back if the project cannot be accepted', function () {
        $this->project->forceFill(['status' => ProjectStatus::Delivered])->save();
        $active = Assignment::factory()->active()->create(['project_id' => $this->project->id]);

        expect(fn () => app(AcceptProject::class)($this->project, $this->dm))->toThrow(ActionBlocked::class);
        expect($active->refresh()->status)->toBe(AssignmentStatus::Active);
    });
});

describe('policy', function () {
    it('lets a contributor see only their own seats and keeps clients out', function () {
        $mine = Assignment::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->contributor->id]);
        $theirs = Assignment::factory()->create(['project_id' => $this->project->id]);
        $client = clientUser($this->project->organization);

        expect(Gate::forUser($this->contributor)->allows('view', $mine))->toBeTrue()
            ->and(Gate::forUser($this->contributor)->allows('view', $theirs))->toBeFalse()
            ->and(Gate::forUser($client)->allows('view', $mine))->toBeFalse()
            ->and(Gate::forUser($client)->allows('viewAny', Assignment::class))->toBeFalse()
            ->and(Gate::forUser($this->dm)->allows('view', $theirs))->toBeTrue()
            ->and(Gate::forUser(adminUser(AdminRole::FinanceOps))->allows('view', $theirs))->toBeTrue();
    });
});

describe('admin team panel', function () {
    beforeEach(function () {
        $this->panel = fn ($actor) => Livewire::actingAs($actor)
            ->test(AssignmentsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class]);
    });

    it('invites a contributor from the project page', function () {
        Notification::fake();

        ($this->panel)($this->dm)
            ->callAction(TestAction::make('invite')->table(), [
                'user_id' => $this->contributor->id,
                'role_title' => 'Data analyst',
                'rate_type' => RateType::Fixed->value,
                'agreed_amount' => 45000,
            ])
            ->assertHasNoActionErrors();

        $assignment = Assignment::query()->sole();

        expect($assignment->user_id)->toBe($this->contributor->id)
            ->and($assignment->role_title)->toBe('Data analyst')
            ->and($assignment->status)->toBe(AssignmentStatus::Invited);

        Notification::assertSentTo($this->contributor, AssignmentInvited::class);
    });

    it('shows remove only on active seats and invite only to project editors', function () {
        $active = Assignment::factory()->active()->create(['project_id' => $this->project->id]);
        $invited = Assignment::factory()->create(['project_id' => $this->project->id]);

        $panel = ($this->panel)($this->dm);
        $panel->assertActionVisible(TestAction::make('invite')->table())
            ->assertActionVisible(TestAction::make('remove')->table($active))
            ->assertActionHidden(TestAction::make('remove')->table($invited));

        $finance = ($this->panel)(adminUser(AdminRole::FinanceOps));
        $finance->assertActionHidden(TestAction::make('invite')->table())
            ->assertActionHidden(TestAction::make('remove')->table($active));
    });

    it('removes a contributor with a reason', function () {
        $active = Assignment::factory()->active()->create(['project_id' => $this->project->id]);

        ($this->panel)($this->dm)
            ->callAction(TestAction::make('remove')->table($active), ['reason' => 'Left the project'])
            ->assertHasNoActionErrors();

        expect($active->refresh()->status)->toBe(AssignmentStatus::Removed);
    });
});
