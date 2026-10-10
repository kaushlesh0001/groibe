<?php

use App\Actions\Deliverables\CreateDeliverable;
use App\Actions\Deliverables\UpdateDeliverable;
use App\Actions\Milestones\ReopenMilestone;
use App\Actions\Milestones\RequestMilestoneChanges;
use App\Actions\Milestones\ShareMilestone;
use App\Actions\Milestones\SyncMilestoneStatus;
use App\Actions\Projects\MarkProjectDelivered;
use App\Actions\Tasks\CreateTask;
use App\Enums\AdminRole;
use App\Enums\AssignmentStatus;
use App\Enums\MilestoneStatus;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Filament\Admin\Resources\Deliverables\Pages\ListDeliverables;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\RelationManagers\DeliverablesRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\MilestonesRelationManager;
use App\Models\Assignment;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Submission;
use App\Models\Task;
use App\Notifications\DeliverableShared;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $this->dm->id]);
    $this->seat = Assignment::factory()->active()->create(['project_id' => $this->project->id]);
    $this->milestone = Milestone::factory()->status(MilestoneStatus::InQa)->create(['project_id' => $this->project->id, 'sort' => 1]);
    $this->task = Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);
    $this->approved = Submission::factory()->status(SubmissionStatus::QaApproved)->create(['task_id' => $this->task->id, 'assignment_id' => $this->seat->id]);
});

function deliverableOn(Milestone $milestone, ?Submission $submission = null): Deliverable
{
    $deliverable = Deliverable::factory()->create(['milestone_id' => $milestone->id]);
    $deliverable->submissions()->attach($submission ?? Submission::query()->whereHas('task', fn ($t) => $t->where('milestone_id', $milestone->id))->firstOrFail());

    return $deliverable;
}

describe('building a deliverable', function () {
    it('bundles approved submissions of the milestone', function () {
        $deliverable = app(CreateDeliverable::class)($this->milestone, $this->dm, ['title' => 'Market report', 'summary' => 'Final PDF', 'submission_ids' => [$this->approved->id]]);

        expect($deliverable->title)->toBe('Market report')
            ->and($deliverable->shared_at)->toBeNull()
            ->and($deliverable->submissions->modelKeys())->toBe([$this->approved->id])
            ->and(Activity::query()->where('subject_type', Deliverable::class)->where('event', 'created')->count())->toBe(1);
    });

    it('refuses anything that is not an approved submission of this milestone', function (string $case) {
        $otherTask = Task::factory()->create(['milestone_id' => Milestone::factory()->create(['project_id' => $this->project->id])->id, 'assignment_id' => $this->seat->id]);
        $own = fn (SubmissionStatus $status) => Submission::factory()->status($status)->create([
            'task_id' => Task::factory()->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id])->id,
            'assignment_id' => $this->seat->id,
        ]);

        $ids = match ($case) {
            'unreviewed' => [$own(SubmissionStatus::Submitted)->id],
            'changes_requested' => [$own(SubmissionStatus::QaChangesRequested)->id],
            'other_milestone' => [Submission::factory()->status(SubmissionStatus::QaApproved)->create(['task_id' => $otherTask->id, 'assignment_id' => $this->seat->id])->id],
            'good_and_bad' => [$this->approved->id, $own(SubmissionStatus::Submitted)->id],
            'missing_id' => [$this->approved->id, 999999],
            'none' => [],
        };

        expect(fn () => app(CreateDeliverable::class)($this->milestone, $this->dm, ['title' => 'x', 'submission_ids' => $ids]))->toThrow(ActionBlocked::class);
        expect(Deliverable::query()->count())->toBe(0);
    })->with(['unreviewed', 'changes_requested', 'other_milestone', 'good_and_bad', 'missing_id', 'none']);

    it('is only possible while the milestone is being built', function (MilestoneStatus $status, bool $allowed) {
        $this->milestone->forceFill(['status' => $status])->save();

        if ($allowed) {
            expect(app(CreateDeliverable::class)($this->milestone, $this->dm, ['title' => 'x', 'submission_ids' => [$this->approved->id]]))->toBeInstanceOf(Deliverable::class);
        } else {
            expect(fn () => app(CreateDeliverable::class)($this->milestone, $this->dm, ['title' => 'x', 'submission_ids' => [$this->approved->id]]))->toThrow(ActionBlocked::class, 'before it is shared');
        }
    })->with([
        [MilestoneStatus::InProgress, true],
        [MilestoneStatus::InQa, true],
        [MilestoneStatus::Planned, false],
        [MilestoneStatus::Shared, false],
        [MilestoneStatus::ClientAccepted, false],
        [MilestoneStatus::ClientChangesRequested, false],
    ]);

    it('updates the text and the bundle until the milestone is shared', function () {
        $deliverable = deliverableOn($this->milestone);
        $second = Submission::factory()->status(SubmissionStatus::QaApproved)->create([
            'task_id' => Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id])->id,
            'assignment_id' => $this->seat->id,
        ]);

        app(UpdateDeliverable::class)($deliverable, $this->dm, ['title' => 'Renamed', 'submission_ids' => [$second->id]]);

        expect($deliverable->refresh()->title)->toBe('Renamed')
            ->and($deliverable->submissions()->pluck('submissions.id')->all())->toBe([$second->id]);

        $this->milestone->forceFill(['status' => MilestoneStatus::Shared])->save();

        expect(fn () => app(UpdateDeliverable::class)($deliverable, $this->dm, ['title' => 'Late edit']))->toThrow(ActionBlocked::class);
        expect($deliverable->refresh()->title)->toBe('Renamed');
    });

    it('freezes the plan of a closed project', function () {
        $this->project->forceFill(['status' => ProjectStatus::Closed])->save();

        expect(fn () => app(CreateDeliverable::class)($this->milestone, $this->dm, ['title' => 'x', 'submission_ids' => [$this->approved->id]]))->toThrow(ActionBlocked::class);
    });

    it('is limited to admins with deliverable write access', function (string $who) {
        $actor = match ($who) {
            'community_manager' => adminUser(AdminRole::CommunityManager),
            'finance_ops' => adminUser(AdminRole::FinanceOps),
            'contributor' => $this->seat->user,
            'client' => clientUser($this->project->organization),
        };
        $existing = deliverableOn($this->milestone);

        expect(fn () => app(CreateDeliverable::class)($this->milestone, $actor, ['title' => 'x', 'submission_ids' => [$this->approved->id]]))->toThrow(AuthorizationException::class)
            ->and(fn () => app(UpdateDeliverable::class)($existing, $actor, ['title' => 'x']))->toThrow(AuthorizationException::class);
    })->with(['community_manager', 'finance_ops', 'contributor', 'client']);
});

describe('sharing a milestone', function () {
    beforeEach(function () {
        $this->deliverable = deliverableOn($this->milestone);
        $this->owner = clientUser($this->project->organization, OrgRole::Owner);
        $this->manager = clientUser($this->project->organization, OrgRole::Manager);
        $this->viewer = clientUser($this->project->organization, OrgRole::Viewer);
    });

    it('shares approved work, stamps the deliverables and tells owners and managers only', function () {
        Notification::fake();
        $this->milestone->forceFill(['client_note' => 'old note'])->save();

        $milestone = app(ShareMilestone::class)($this->milestone, $this->dm);

        expect($milestone->status)->toBe(MilestoneStatus::Shared)
            ->and($milestone->shared_at)->not->toBeNull()
            ->and($milestone->client_note)->toBeNull()
            ->and($this->deliverable->refresh()->shared_at)->not->toBeNull()
            ->and(Activity::query()->where('log_name', 'milestone')->where('event', 'shared')->count())->toBe(1);

        Notification::assertSentTo($this->owner, DeliverableShared::class);
        Notification::assertSentTo($this->manager, DeliverableShared::class);
        Notification::assertNotSentTo($this->viewer, DeliverableShared::class);
    });

    it('needs a milestone in QA', function (MilestoneStatus $status) {
        $this->milestone->forceFill(['status' => $status])->save();

        expect(fn () => app(ShareMilestone::class)($this->milestone, $this->dm))->toThrow(IllegalTransition::class);
    })->with([MilestoneStatus::Planned, MilestoneStatus::InProgress, MilestoneStatus::Shared, MilestoneStatus::ClientAccepted, MilestoneStatus::ClientChangesRequested]);

    it('needs every task approved', function (TaskStatus $status) {
        Task::factory()->status($status)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);

        expect(fn () => app(ShareMilestone::class)($this->milestone, $this->dm))->toThrow(ActionBlocked::class, 'Every task');
        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::InQa)->and($this->deliverable->refresh()->shared_at)->toBeNull();
    })->with([TaskStatus::Submitted, TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::ChangesRequested]);

    it('needs a deliverable with approved work', function () {
        $this->deliverable->submissions()->detach();

        expect(fn () => app(ShareMilestone::class)($this->milestone, $this->dm))->toThrow(ActionBlocked::class, 'deliverable');

        $this->deliverable->delete();

        expect(fn () => app(ShareMilestone::class)($this->milestone, $this->dm))->toThrow(ActionBlocked::class, 'deliverable');
    });

    it('only happens while the project is active or in QA', function (ProjectStatus $status, bool $allowed) {
        $this->project->forceFill(['status' => $status])->save();

        if ($allowed) {
            expect(app(ShareMilestone::class)($this->milestone, $this->dm)->status)->toBe(MilestoneStatus::Shared);
        } else {
            expect(fn () => app(ShareMilestone::class)($this->milestone, $this->dm))->toThrow(ActionBlocked::class);
        }
    })->with([
        [ProjectStatus::Active, true],
        [ProjectStatus::InQa, true],
        [ProjectStatus::OnHold, false],
        [ProjectStatus::Delivered, false],
        [ProjectStatus::Closed, false],
    ]);

    it('is an admin action only', function (string $who) {
        $actor = match ($who) {
            'community_manager' => adminUser(AdminRole::CommunityManager),
            'finance_ops' => adminUser(AdminRole::FinanceOps),
            'contributor' => $this->seat->user,
            'client_owner' => $this->owner,
        };

        expect(fn () => app(ShareMilestone::class)($this->milestone, $actor))->toThrow(AuthorizationException::class);
        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::InQa);
    })->with(['community_manager', 'finance_ops', 'contributor', 'client_owner']);

    it('runs a full change loop and shares again with a clean slate', function () {
        app(ShareMilestone::class)($this->milestone, $this->dm);
        app(RequestMilestoneChanges::class)($this->milestone->refresh(), $this->owner, 'Add the regional split');

        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::ClientChangesRequested)
            ->and($this->milestone->client_note)->toBe('Add the regional split');

        app(ReopenMilestone::class)($this->milestone, $this->dm);
        // Every task is approved, so the milestone goes straight back to QA: the admin can adjust and share again.
        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::InQa)
            ->and($this->milestone->client_note)->toBe('Add the regional split');

        $extra = app(CreateTask::class)($this->milestone, $this->dm, ['title' => 'Regional split']);
        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::InProgress);

        $extra->forceFill(['status' => TaskStatus::Approved])->save();
        app(SyncMilestoneStatus::class)($this->milestone);
        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::InQa);

        app(ShareMilestone::class)($this->milestone, $this->dm);

        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::Shared)
            ->and($this->milestone->client_note)->toBeNull();
    });
});

describe('reopening a milestone', function () {
    it('only reopens a milestone the client sent back', function (MilestoneStatus $status) {
        $this->milestone->forceFill(['status' => $status])->save();

        expect(fn () => app(ReopenMilestone::class)($this->milestone, $this->dm))->toThrow(IllegalTransition::class);
    })->with([MilestoneStatus::Planned, MilestoneStatus::InProgress, MilestoneStatus::InQa, MilestoneStatus::Shared, MilestoneStatus::ClientAccepted]);

    it('stays in progress while follow-up work is pending', function () {
        $this->milestone->forceFill(['status' => MilestoneStatus::ClientChangesRequested])->save();
        Task::factory()->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);

        app(ReopenMilestone::class)($this->milestone, $this->dm);

        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::InProgress)
            ->and(Activity::query()->where('log_name', 'milestone')->where('event', 'reopened')->count())->toBe(1);
    });

    it('is an admin action only', function () {
        $this->milestone->forceFill(['status' => MilestoneStatus::ClientChangesRequested])->save();

        expect(fn () => app(ReopenMilestone::class)($this->milestone, clientUser($this->project->organization)))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ReopenMilestone::class)($this->milestone, adminUser(AdminRole::FinanceOps)))->toThrow(AuthorizationException::class);
    });
});

describe('adding work to a milestone in QA', function () {
    it('pulls it back to in progress (SCR-015)', function () {
        app(CreateTask::class)($this->milestone, $this->dm, ['title' => 'One more thing']);

        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::InProgress);
    });
});

describe('delivering the project', function () {
    it('needs every milestone shared or later, and at least one', function () {
        $this->project->forceFill(['status' => ProjectStatus::InQa])->save();

        expect(fn () => app(MarkProjectDelivered::class)($this->project, $this->dm))->toThrow(ActionBlocked::class, 'shared with the client');

        $this->milestone->forceFill(['status' => MilestoneStatus::Shared])->save();
        $pending = Milestone::factory()->status(MilestoneStatus::InProgress)->create(['project_id' => $this->project->id]);

        expect(fn () => app(MarkProjectDelivered::class)($this->project, $this->dm))->toThrow(ActionBlocked::class);

        $pending->forceFill(['status' => MilestoneStatus::ClientChangesRequested])->save();

        expect(app(MarkProjectDelivered::class)($this->project, $this->dm)->status)->toBe(ProjectStatus::Delivered);
    });

    it('refuses a project with no milestones', function () {
        $empty = Project::factory()->status(ProjectStatus::InQa)->create();

        expect(fn () => app(MarkProjectDelivered::class)($empty, $this->dm))->toThrow(ActionBlocked::class);
    });

    it('goes straight to accepted when the client already accepted every milestone', function () {
        $this->project->forceFill(['status' => ProjectStatus::InQa])->save();
        $this->milestone->forceFill(['status' => MilestoneStatus::ClientAccepted])->save();

        $project = app(MarkProjectDelivered::class)($this->project, $this->dm);

        expect($project->status)->toBe(ProjectStatus::Accepted)
            ->and($this->seat->refresh()->status)->toBe(AssignmentStatus::Completed)
            ->and(Activity::query()->where('log_name', 'project')->pluck('event')->all())->toBe(['delivered', 'accepted']);
    });
});

describe('admin screens', function () {
    beforeEach(function () {
        $this->manager = fn (string $class, $actor = null) => Livewire::actingAs($actor ?? $this->dm)
            ->test($class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class]);
    });

    it('builds a deliverable from the project page', function () {
        ($this->manager)(DeliverablesRelationManager::class)
            ->callAction(TestAction::make('addDeliverable')->table(), [
                'milestone_id' => $this->milestone->id,
                'title' => 'Final report',
                'summary' => 'Everything in one place',
                'submission_ids' => [$this->approved->id],
            ])
            ->assertHasNoActionErrors();

        $deliverable = Deliverable::query()->sole();
        expect($deliverable->title)->toBe('Final report')->and($deliverable->submissions()->count())->toBe(1);

        ($this->manager)(DeliverablesRelationManager::class)
            ->callAction(TestAction::make('editDeliverable')->table($deliverable), ['title' => 'Final report v2', 'summary' => null, 'submission_ids' => [$this->approved->id]])
            ->assertHasNoActionErrors();

        expect($deliverable->refresh()->title)->toBe('Final report v2');
    });

    it('shares a milestone from the milestones tab and offers reopen only when changes were requested', function () {
        deliverableOn($this->milestone);
        $sentBack = Milestone::factory()->status(MilestoneStatus::ClientChangesRequested)->create(['project_id' => $this->project->id, 'sort' => 2]);

        $panel = ($this->manager)(MilestonesRelationManager::class)
            ->assertActionVisible(TestAction::make('shareMilestone')->table($this->milestone))
            ->assertActionHidden(TestAction::make('shareMilestone')->table($sentBack))
            ->assertActionVisible(TestAction::make('reopenMilestone')->table($sentBack))
            ->assertActionHidden(TestAction::make('reopenMilestone')->table($this->milestone));

        $panel->callAction(TestAction::make('shareMilestone')->table($this->milestone))->assertHasNoActionErrors();

        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::Shared);
    });

    it('keeps the deliverables tab and list away from roles without deliverable access', function () {
        asUser($this->dm);
        expect(DeliverablesRelationManager::canViewForRecord($this->project, EditProject::class))->toBeTrue();

        foreach ([AdminRole::CommunityManager, AdminRole::FinanceOps] as $role) {
            asUser(adminUser($role));
            expect(DeliverablesRelationManager::canViewForRecord($this->project, EditProject::class))->toBeFalse();
            Livewire::test(ListDeliverables::class)->assertForbidden();
        }
    });

    it('lists deliverables across projects for delivery managers', function () {
        $deliverable = deliverableOn($this->milestone);

        Livewire::actingAs($this->dm)->test(ListDeliverables::class)->assertCanSeeTableRecords([$deliverable]);
    });
});
