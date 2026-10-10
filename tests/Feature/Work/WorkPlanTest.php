<?php

use App\Actions\Milestones\CreateMilestone;
use App\Actions\Milestones\DeleteMilestone;
use App\Actions\Milestones\ReorderMilestones;
use App\Actions\Milestones\SyncMilestoneStatus;
use App\Actions\Milestones\UpdateMilestone;
use App\Actions\Tasks\AssignTask;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\UpdateTask;
use App\Enums\AdminRole;
use App\Enums\AssignmentStatus;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\Pages\ProjectWorkspace;
use App\Filament\Admin\Resources\Projects\RelationManagers\MilestonesRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\TasksRelationManager;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Submission;
use App\Models\Task;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskChanged;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $this->dm->id]);
    $this->seat = Assignment::factory()->active()->create(['project_id' => $this->project->id]);
});

function milestoneOf(Project $project, array $attributes = []): Milestone
{
    return Milestone::factory()->create(['project_id' => $project->id, ...$attributes]);
}

function taskOf(Milestone $milestone, array $attributes = []): Task
{
    return Task::factory()->create(['milestone_id' => $milestone->id, ...$attributes]);
}

describe('milestones', function () {
    it('creates planned milestones at the end of the plan', function () {
        $first = app(CreateMilestone::class)($this->project, $this->dm, ['title' => 'Research', 'due_date' => '2026-12-01', 'value' => 50000]);
        $second = app(CreateMilestone::class)($this->project, $this->dm, ['title' => 'Build']);
        $elsewhere = app(CreateMilestone::class)(Project::factory()->create(), $this->dm, ['title' => 'Other']);

        expect($first->status)->toBe(MilestoneStatus::Planned)
            ->and([$first->sort, $second->sort, $elsewhere->sort])->toBe([1, 2, 1])
            ->and($first->due_date->toDateString())->toBe('2026-12-01')
            ->and($first->value)->toBe('50000.00')
            ->and(Activity::query()->where('subject_type', Milestone::class)->where('event', 'created')->count())->toBe(3);
    });

    it('updates descriptive fields only', function () {
        $milestone = milestoneOf($this->project, ['sort' => 3]);

        app(UpdateMilestone::class)($milestone, $this->dm, ['title' => 'Renamed', 'status' => 'client_accepted', 'sort' => 99, 'shared_at' => now()]);

        $milestone->refresh();

        expect($milestone->title)->toBe('Renamed')
            ->and($milestone->status)->toBe(MilestoneStatus::Planned)
            ->and($milestone->sort)->toBe(3)
            ->and($milestone->shared_at)->toBeNull();
    });

    it('deletes only a planned milestone with no tasks', function () {
        $empty = milestoneOf($this->project);
        $withTask = milestoneOf($this->project);
        taskOf($withTask);
        $started = milestoneOf($this->project, ['status' => MilestoneStatus::InProgress]);

        app(DeleteMilestone::class)($empty, $this->dm);

        expect(Milestone::query()->whereKey($empty->id)->exists())->toBeFalse()
            ->and(fn () => app(DeleteMilestone::class)($withTask, $this->dm))->toThrow(ActionBlocked::class, 'planned milestone with no tasks')
            ->and(fn () => app(DeleteMilestone::class)($started, $this->dm))->toThrow(ActionBlocked::class);
    });

    it('reorders every milestone of the project and nothing else', function () {
        [$a, $b, $c] = collect([1, 2, 3])->map(fn ($n) => milestoneOf($this->project, ['sort' => $n]))->all();
        $other = milestoneOf(Project::factory()->create(), ['sort' => 1]);

        app(ReorderMilestones::class)($this->project, $this->dm, [$c->id, $a->id, $b->id]);

        expect($this->project->milestones()->ordered()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id])
            ->and($other->refresh()->sort)->toBe(1);
    });

    it('rejects an order that is incomplete, repeated or foreign', function (string $case) {
        [$a, $b] = collect([1, 2])->map(fn ($n) => milestoneOf($this->project, ['sort' => $n]))->all();
        $foreign = milestoneOf(Project::factory()->create());

        $ids = match ($case) {
            'missing' => [$a->id],
            'repeated' => [$a->id, $a->id],
            'foreign' => [$a->id, $foreign->id],
            'extra' => [$a->id, $b->id, $foreign->id],
        };

        expect(fn () => app(ReorderMilestones::class)($this->project, $this->dm, $ids))->toThrow(ActionBlocked::class, 'exactly once');
        expect($this->project->milestones()->ordered()->pluck('id')->all())->toBe([$a->id, $b->id]);
    })->with(['missing', 'repeated', 'foreign', 'extra']);

    it('freezes the plan of closed and cancelled projects', function (ProjectStatus $status) {
        $milestone = milestoneOf($this->project);
        $this->project->forceFill(['status' => $status])->save();

        expect(fn () => app(CreateMilestone::class)($this->project, $this->dm, ['title' => 'x']))->toThrow(ActionBlocked::class)
            ->and(fn () => app(UpdateMilestone::class)($milestone, $this->dm, ['title' => 'x']))->toThrow(ActionBlocked::class)
            ->and(fn () => app(DeleteMilestone::class)($milestone, $this->dm))->toThrow(ActionBlocked::class)
            ->and(fn () => app(ReorderMilestones::class)($this->project, $this->dm, [$milestone->id]))->toThrow(ActionBlocked::class)
            ->and(fn () => app(CreateTask::class)($milestone, $this->dm, ['title' => 'x']))->toThrow(ActionBlocked::class);
    })->with([ProjectStatus::Closed, ProjectStatus::Cancelled]);

    it('is limited to admins with milestone write access', function (string $who) {
        $actor = match ($who) {
            'community_manager' => adminUser(AdminRole::CommunityManager),
            'finance_ops' => adminUser(AdminRole::FinanceOps),
            'contributor' => $this->seat->user,
            'client' => clientUser($this->project->organization),
        };
        $milestone = milestoneOf($this->project);

        expect(fn () => app(CreateMilestone::class)($this->project, $actor, ['title' => 'x']))->toThrow(AuthorizationException::class)
            ->and(fn () => app(UpdateMilestone::class)($milestone, $actor, ['title' => 'x']))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeleteMilestone::class)($milestone, $actor))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ReorderMilestones::class)($this->project, $actor, [$milestone->id]))->toThrow(AuthorizationException::class);
    })->with(['community_manager', 'finance_ops', 'contributor', 'client']);
});

describe('tasks', function () {
    beforeEach(function () {
        $this->milestone = milestoneOf($this->project);
    });

    it('creates a todo task under the milestone and derives the project', function () {
        $task = app(CreateTask::class)($this->milestone, $this->dm, ['title' => 'Draft survey', 'estimate_hours' => 6.5, 'due_date' => '2026-12-05']);

        expect($task->status)->toBe(TaskStatus::Todo)
            ->and($task->project_id)->toBe($this->project->id)
            ->and($task->assignment_id)->toBeNull()
            ->and($task->estimate_hours)->toBe('6.50')
            ->and($task->due_date->toDateString())->toBe('2026-12-05');
    });

    it('can assign on creation and notifies the contributor', function () {
        Notification::fake();

        $task = app(CreateTask::class)($this->milestone, $this->dm, ['title' => 'Draft survey', 'assignment_id' => $this->seat->id]);

        expect($task->assignment_id)->toBe($this->seat->id);
        Notification::assertSentTo($this->seat->user, TaskAssigned::class);
    });

    it('only takes tasks while the milestone is planned or in progress', function (MilestoneStatus $status) {
        $milestone = milestoneOf($this->project, ['status' => $status]);

        expect(fn () => app(CreateTask::class)($milestone, $this->dm, ['title' => 'x']))->toThrow(ActionBlocked::class, 'planned or in progress');
    })->with([MilestoneStatus::InQa, MilestoneStatus::Shared, MilestoneStatus::ClientAccepted, MilestoneStatus::ClientChangesRequested]);

    it('only assigns to an active seat of the same project', function (string $case) {
        $seat = match ($case) {
            'invited' => Assignment::factory()->create(['project_id' => $this->project->id]),
            'removed' => Assignment::factory()->status(AssignmentStatus::Removed)->create(['project_id' => $this->project->id]),
            'completed' => Assignment::factory()->status(AssignmentStatus::Completed)->create(['project_id' => $this->project->id]),
            'other_project' => Assignment::factory()->active()->create(),
        };

        expect(fn () => app(CreateTask::class)($this->milestone, $this->dm, ['title' => 'x', 'assignment_id' => $seat->id]))->toThrow(ActionBlocked::class, 'active member')
            ->and(fn () => app(AssignTask::class)(taskOf($this->milestone), $this->dm, $seat->id))->toThrow(ActionBlocked::class, 'active member');

        expect(Task::query()->whereNotNull('assignment_id')->count())->toBe(0);
    })->with(['invited', 'removed', 'completed', 'other_project']);

    it('updates planning fields and tells the assignee when they change', function () {
        Notification::fake();
        $task = taskOf($this->milestone, ['assignment_id' => $this->seat->id, 'due_date' => '2026-12-01']);

        app(UpdateTask::class)($task, $this->dm, ['due_date' => '2026-12-09', 'status' => 'approved', 'assignment_id' => null]);

        $task->refresh();

        expect($task->due_date->toDateString())->toBe('2026-12-09')
            ->and($task->status)->toBe(TaskStatus::Todo)
            ->and($task->assignment_id)->toBe($this->seat->id);
        Notification::assertSentTo($this->seat->user, TaskChanged::class);
    });

    it('stays quiet when nothing changed or nobody is assigned', function () {
        Notification::fake();
        $assigned = taskOf($this->milestone, ['assignment_id' => $this->seat->id]);
        $loose = taskOf($this->milestone);

        app(UpdateTask::class)($assigned, $this->dm, ['title' => $assigned->title]);
        app(UpdateTask::class)($loose, $this->dm, ['title' => 'New title']);

        Notification::assertNothingSent();
    });

    it('does not change an approved task', function () {
        $task = taskOf($this->milestone, ['status' => TaskStatus::Approved]);

        expect(fn () => app(UpdateTask::class)($task, $this->dm, ['title' => 'x']))->toThrow(ActionBlocked::class, 'approved task');
        expect(fn () => app(AssignTask::class)($task, $this->dm, $this->seat->id))->toThrow(ActionBlocked::class);
        expect(fn () => app(DeleteTask::class)($task, $this->dm))->toThrow(ActionBlocked::class);
    });

    it('assigns, reassigns and unassigns, telling both contributors', function () {
        Notification::fake();
        $second = Assignment::factory()->active()->create(['project_id' => $this->project->id]);
        $task = taskOf($this->milestone);

        app(AssignTask::class)($task, $this->dm, $this->seat->id);
        expect($task->refresh()->assignment_id)->toBe($this->seat->id);
        Notification::assertSentTo($this->seat->user, TaskAssigned::class);

        app(AssignTask::class)($task, $this->dm, $second->id);
        expect($task->refresh()->assignment_id)->toBe($second->id);
        Notification::assertSentTo($second->user, TaskAssigned::class);
        Notification::assertSentTo($this->seat->user, TaskChanged::class);

        app(AssignTask::class)($task, $this->dm, null);
        expect($task->refresh()->assignment_id)->toBeNull();
        Notification::assertSentTo($second->user, TaskChanged::class);

        expect(Activity::query()->where('log_name', 'task')->where('event', 'assigned')->count())->toBe(3);
    });

    it('treats assigning the same seat again as a no-op', function () {
        Notification::fake();
        $task = taskOf($this->milestone, ['assignment_id' => $this->seat->id]);

        app(AssignTask::class)($task, $this->dm, $this->seat->id);

        Notification::assertNothingSent();
        expect(Activity::query()->where('log_name', 'task')->count())->toBe(0);
    });

    it('only assigns tasks that have not started', function (TaskStatus $status) {
        $task = taskOf($this->milestone, ['status' => $status, 'assignment_id' => $this->seat->id]);
        $other = Assignment::factory()->active()->create(['project_id' => $this->project->id]);

        expect(fn () => app(AssignTask::class)($task, $this->dm, $other->id))->toThrow(ActionBlocked::class, 'not started');
        expect($task->refresh()->assignment_id)->toBe($this->seat->id);
    })->with([TaskStatus::InProgress, TaskStatus::Submitted, TaskStatus::ChangesRequested]);

    it('deletes only an untouched task', function () {
        $fresh = taskOf($this->milestone);
        $busy = taskOf($this->milestone, ['status' => TaskStatus::InProgress]);
        $withWork = taskOf($this->milestone);
        Submission::factory()->create(['task_id' => $withWork->id, 'assignment_id' => $this->seat->id]);

        app(DeleteTask::class)($fresh, $this->dm);

        expect(Task::query()->whereKey($fresh->id)->exists())->toBeFalse()
            ->and(fn () => app(DeleteTask::class)($busy, $this->dm))->toThrow(ActionBlocked::class)
            ->and(fn () => app(DeleteTask::class)($withWork, $this->dm))->toThrow(ActionBlocked::class);
    });

    it('moves the milestone to QA when deleting the last unfinished task', function () {
        $this->milestone->forceFill(['status' => MilestoneStatus::InProgress])->save();
        taskOf($this->milestone, ['status' => TaskStatus::Approved]);
        taskOf($this->milestone, ['status' => TaskStatus::Submitted]);
        $left = taskOf($this->milestone);

        app(DeleteTask::class)($left, $this->dm);

        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::InQa);
    });

    it('is limited to admins with task write access', function (string $who) {
        $actor = match ($who) {
            'community_manager' => adminUser(AdminRole::CommunityManager),
            'finance_ops' => adminUser(AdminRole::FinanceOps),
            'the_assignee' => $this->seat->user,
            'client' => clientUser($this->project->organization),
        };
        $task = taskOf($this->milestone, ['assignment_id' => $this->seat->id]);

        expect(fn () => app(CreateTask::class)($this->milestone, $actor, ['title' => 'x']))->toThrow(AuthorizationException::class)
            ->and(fn () => app(UpdateTask::class)($task, $actor, ['title' => 'x']))->toThrow(AuthorizationException::class)
            ->and(fn () => app(AssignTask::class)($task, $actor, null))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeleteTask::class)($task, $actor))->toThrow(AuthorizationException::class);

        expect($task->refresh()->assignment_id)->toBe($this->seat->id);
    })->with(['community_manager', 'finance_ops', 'the_assignee', 'client']);
});

describe('automatic milestone status', function () {
    beforeEach(function () {
        $this->milestone = milestoneOf($this->project);
        $this->sync = fn () => app(SyncMilestoneStatus::class)($this->milestone)->status;
    });

    it('stays planned while every task is todo or there are no tasks', function () {
        expect(($this->sync)())->toBe(MilestoneStatus::Planned);

        taskOf($this->milestone);
        taskOf($this->milestone);

        expect(($this->sync)())->toBe(MilestoneStatus::Planned);
    });

    it('starts when the first task starts', function () {
        taskOf($this->milestone);
        taskOf($this->milestone, ['status' => TaskStatus::InProgress]);

        expect(($this->sync)())->toBe(MilestoneStatus::InProgress);

        $event = Activity::query()->where('log_name', 'milestone')->sole();
        expect($event->event)->toBe('in_progress')->and($event->properties['automatic'])->toBeTrue();
    });

    it('goes to QA once every task is submitted or approved', function () {
        taskOf($this->milestone, ['status' => TaskStatus::Approved]);
        taskOf($this->milestone, ['status' => TaskStatus::InProgress]);

        expect(($this->sync)())->toBe(MilestoneStatus::InProgress);

        Task::query()->where('status', TaskStatus::InProgress)->update(['status' => TaskStatus::Submitted]);

        expect(($this->sync)())->toBe(MilestoneStatus::InQa);
    });

    it('goes straight through when all tasks are already finished', function () {
        taskOf($this->milestone, ['status' => TaskStatus::Submitted]);

        expect(($this->sync)())->toBe(MilestoneStatus::InQa);
        expect(Activity::query()->where('log_name', 'milestone')->pluck('event')->all())->toBe(['in_progress', 'in_qa']);
    });

    it('does not wait on tasks needing changes and never moves backwards or past QA', function () {
        taskOf($this->milestone, ['status' => TaskStatus::Submitted]);
        taskOf($this->milestone, ['status' => TaskStatus::ChangesRequested]);

        expect(($this->sync)())->toBe(MilestoneStatus::InProgress);

        $this->milestone->refresh()->forceFill(['status' => MilestoneStatus::InQa])->save();
        taskOf($this->milestone);

        expect(($this->sync)())->toBe(MilestoneStatus::InQa);

        $this->milestone->forceFill(['status' => MilestoneStatus::Shared])->save();
        Task::query()->update(['status' => TaskStatus::Approved]);

        expect(($this->sync)())->toBe(MilestoneStatus::Shared);
    });
});

describe('read access', function () {
    it('shows milestones to clients of the project organization once shared, never other organizations', function () {
        $planned = milestoneOf($this->project);
        $shared = milestoneOf($this->project, ['status' => MilestoneStatus::Shared]);
        $client = clientUser($this->project->organization);
        $stranger = clientUser();

        expect(Gate::forUser($client)->allows('view', $planned))->toBeFalse()
            ->and(Gate::forUser($client)->allows('view', $shared))->toBeTrue()
            ->and(Gate::forUser($stranger)->allows('view', $shared))->toBeFalse()
            ->and(Gate::forUser($client)->allows('update', $shared))->toBeFalse();
    });

    it('shows milestones to contributors only on projects they work on', function () {
        $milestone = milestoneOf($this->project);
        $outsider = contributorIn(OnboardingState::Active);
        $invitedOnly = Assignment::factory()->create(['project_id' => $this->project->id])->user;
        $completed = Assignment::factory()->status(AssignmentStatus::Completed)->create(['project_id' => $this->project->id])->user;
        $removed = Assignment::factory()->status(AssignmentStatus::Removed)->create(['project_id' => $this->project->id])->user;

        expect(Gate::forUser($this->seat->user)->allows('view', $milestone))->toBeTrue()
            ->and(Gate::forUser($completed)->allows('view', $milestone))->toBeTrue()
            ->and(Gate::forUser($outsider)->allows('view', $milestone))->toBeFalse()
            ->and(Gate::forUser($invitedOnly)->allows('view', $milestone))->toBeFalse()
            ->and(Gate::forUser($removed)->allows('view', $milestone))->toBeFalse();
    });

    it('shows a task to its assignee, admins and clients of a shared milestone only', function () {
        $planned = milestoneOf($this->project);
        $shared = milestoneOf($this->project, ['status' => MilestoneStatus::Shared]);
        $mine = taskOf($planned, ['assignment_id' => $this->seat->id]);
        $unassigned = taskOf($planned);
        $sharedTask = taskOf($shared);
        $client = clientUser($this->project->organization);

        expect(Gate::forUser($this->seat->user)->allows('view', $mine))->toBeTrue()
            ->and(Gate::forUser($this->seat->user)->allows('view', $unassigned))->toBeFalse()
            ->and(Gate::forUser($this->dm)->allows('view', $unassigned))->toBeTrue()
            ->and(Gate::forUser(adminUser(AdminRole::FinanceOps))->allows('view', $unassigned))->toBeFalse()
            ->and(Gate::forUser($client)->allows('view', $mine))->toBeFalse()
            ->and(Gate::forUser($client)->allows('view', $sharedTask))->toBeTrue()
            ->and(Gate::forUser(clientUser())->allows('view', $sharedTask))->toBeFalse();
    });
});

describe('admin screens', function () {
    beforeEach(function () {
        $this->manager = fn (string $class, $actor = null) => Livewire::actingAs($actor ?? $this->dm)
            ->test($class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class]);
    });

    it('adds and edits milestones from the project page', function () {
        ($this->manager)(MilestonesRelationManager::class)
            ->callAction(TestAction::make('addMilestone')->table(), ['title' => 'Discovery', 'due_date' => '2026-12-20'])
            ->assertHasNoActionErrors();

        $milestone = Milestone::query()->where('title', 'Discovery')->sole();

        ($this->manager)(MilestonesRelationManager::class)
            ->callAction(TestAction::make('editMilestone')->table($milestone), ['title' => 'Discovery sprint'])
            ->assertHasNoActionErrors();

        expect($milestone->refresh()->title)->toBe('Discovery sprint');
    });

    it('adds, assigns and deletes tasks from the project page', function () {
        $milestone = milestoneOf($this->project);

        ($this->manager)(TasksRelationManager::class)
            ->callAction(TestAction::make('addTask')->table(), [
                'milestone_id' => $milestone->id,
                'title' => 'Interview guide',
                'estimate_hours' => 4,
                'assignment_id' => $this->seat->id,
            ])
            ->assertHasNoActionErrors();

        $task = Task::query()->sole();
        expect($task->assignment_id)->toBe($this->seat->id);

        ($this->manager)(TasksRelationManager::class)
            ->callAction(TestAction::make('assignTask')->table($task), ['assignment_id' => null])
            ->callAction(TestAction::make('deleteTask')->table($task));

        expect(Task::query()->count())->toBe(0);
    });

    it('shows the milestone and task tabs to delivery managers only', function () {
        asUser($this->dm);
        expect(MilestonesRelationManager::canViewForRecord($this->project, EditProject::class))->toBeTrue()
            ->and(TasksRelationManager::canViewForRecord($this->project, EditProject::class))->toBeTrue();

        // Community managers and finance have no milestone or task access (spec 5).
        foreach ([AdminRole::CommunityManager, AdminRole::FinanceOps] as $role) {
            asUser(adminUser($role));
            expect(MilestonesRelationManager::canViewForRecord($this->project, EditProject::class))->toBeFalse()
                ->and(TasksRelationManager::canViewForRecord($this->project, EditProject::class))->toBeFalse();
        }
    });

    it('offers task actions by task state', function () {
        $todo = taskOf(milestoneOf($this->project));
        $started = taskOf(milestoneOf($this->project), ['status' => TaskStatus::InProgress]);
        $approved = taskOf(milestoneOf($this->project), ['status' => TaskStatus::Approved]);

        ($this->manager)(TasksRelationManager::class)
            ->assertActionVisible(TestAction::make('assignTask')->table($todo))
            ->assertActionHidden(TestAction::make('assignTask')->table($started))
            ->assertActionVisible(TestAction::make('deleteTask')->table($todo))
            ->assertActionHidden(TestAction::make('deleteTask')->table($started))
            ->assertActionVisible(TestAction::make('editTask')->table($started))
            ->assertActionHidden(TestAction::make('editTask')->table($approved));
    });

    it('opens the workspace for delivery managers and refuses roles without milestone access', function () {
        milestoneOf($this->project, ['title' => 'Discovery']);

        Livewire::actingAs($this->dm)->test(ProjectWorkspace::class, ['record' => $this->project->getRouteKey()])
            ->assertOk()
            ->assertSee('Discovery');

        foreach ([AdminRole::CommunityManager, AdminRole::FinanceOps] as $role) {
            Livewire::actingAs(adminUser($role))->test(ProjectWorkspace::class, ['record' => $this->project->getRouteKey()])
                ->assertForbidden();
        }
    });

    it('reorders milestones and assigns tasks from the workspace', function () {
        $a = milestoneOf($this->project, ['sort' => 1]);
        $b = milestoneOf($this->project, ['sort' => 2]);
        $task = taskOf($a);

        $page = Livewire::actingAs($this->dm)->test(ProjectWorkspace::class, ['record' => $this->project->getRouteKey()]);

        $page->call('moveMilestone', $b->id, 'up');
        expect($this->project->milestones()->ordered()->pluck('id')->all())->toBe([$b->id, $a->id]);

        $page->call('moveMilestone', $b->id, 'up'); // already first: no change, no error
        expect($this->project->milestones()->ordered()->pluck('id')->all())->toBe([$b->id, $a->id]);

        $page->call('assignTask', $task->id, (string) $this->seat->id);
        expect($task->refresh()->assignment_id)->toBe($this->seat->id);

        $page->call('assignTask', $task->id, '');
        expect($task->refresh()->assignment_id)->toBeNull();
    });

    it('will not touch tasks of another project from the workspace', function () {
        $foreign = taskOf(milestoneOf(Project::factory()->create()));

        Livewire::actingAs($this->dm)->test(ProjectWorkspace::class, ['record' => $this->project->getRouteKey()])
            ->call('assignTask', $foreign->id, (string) $this->seat->id)
            ->assertNotFound();

        expect($foreign->refresh()->assignment_id)->toBeNull();
    });
});
