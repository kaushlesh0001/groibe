<?php

use App\Actions\Assignments\AcceptAssignment;
use App\Actions\Assignments\DeclineAssignment;
use App\Actions\Assignments\RemoveAssignment;
use App\Actions\Deliverables\CreateDeliverable;
use App\Actions\Milestones\AcceptMilestone;
use App\Actions\Milestones\ReopenMilestone;
use App\Actions\Milestones\RequestMilestoneChanges;
use App\Actions\Milestones\ShareMilestone;
use App\Actions\Projects\AcceptProject;
use App\Actions\Projects\ActivateProject;
use App\Actions\Projects\CancelProject;
use App\Actions\Projects\CloseProject;
use App\Actions\Projects\MarkProjectDelivered;
use App\Actions\Projects\MoveProjectToQa;
use App\Actions\Projects\PutProjectOnHold;
use App\Actions\Projects\ReopenProjectForChanges;
use App\Actions\Projects\ResumeProject;
use App\Actions\Projects\StartStaffing;
use App\Actions\Submissions\ApproveSubmission;
use App\Actions\Submissions\RequestSubmissionChanges;
use App\Actions\Tasks\AssignTask;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\ResumeTask;
use App\Actions\Tasks\StartTask;
use App\Actions\Tasks\SubmitWork;
use App\Actions\Tasks\UpdateTask;
use App\Enums\AdminRole;
use App\Enums\AssignmentStatus;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Assignment;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Submission;
use App\Models\Task;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * Every Pass 4 state-changing Action is tried from every state of its subject. Each must succeed from exactly
 * the states the spec allows (6.2, 6.6 to 6.9 and SCR-015, SCR-016) and refuse all others. A refusal is an
 * IllegalTransition, an ActionBlocked guard or an AuthorizationException; anything else fails the test.
 */

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
});

/** A running project with one active seat, one milestone and one task, plus the people who act on it. */
function matrix(): object
{
    $dm = adminUser(AdminRole::DeliveryManager);
    $project = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $dm->id]);
    $me = contributorIn(OnboardingState::Active);
    $seat = Assignment::factory()->active()->create(['project_id' => $project->id, 'user_id' => $me->id]);
    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    $task = Task::factory()->create(['milestone_id' => $milestone->id, 'assignment_id' => $seat->id]);

    return (object) [
        'dm' => $dm,
        'project' => $project,
        'me' => $me,
        'seat' => $seat,
        'milestone' => $milestone,
        'task' => $task,
        'owner' => clientUser($project->organization, OrgRole::Owner),
    ];
}

/** Runs the Action; true when it went through, false when the domain refused it. */
function succeeds(Closure $run): bool
{
    try {
        $run();

        return true;
    } catch (IllegalTransition|ActionBlocked|AuthorizationException) {
        return false;
    }
}

/**
 * @param  list<BackedEnum>  $states
 * @param  Closure(object, BackedEnum): mixed  $run
 * @param  list<BackedEnum>  $expected
 */
function expectOnlyFrom(array $states, Closure $run, array $expected): void
{
    $worked = [];

    foreach ($states as $state) {
        if (succeeds(fn () => $run(matrix(), $state))) {
            $worked[] = $state->value;
        }
    }

    sort($worked);
    $want = array_map(fn (BackedEnum $s) => $s->value, $expected);
    sort($want);

    expect($worked)->toBe($want);
}

describe('assignments (spec 6.6)', function () {
    it('accepts and declines only an invited seat', function (string $action) {
        expectOnlyFrom(AssignmentStatus::cases(), function ($w, AssignmentStatus $s) use ($action) {
            $project = Project::factory()->status(ProjectStatus::Staffing)->create();
            $assignment = Assignment::factory()->status($s)->create(['project_id' => $project->id, 'user_id' => $w->me->id]);

            return app($action)($assignment, $w->me);
        }, [AssignmentStatus::Invited]);
    })->with([AcceptAssignment::class, DeclineAssignment::class]);

    it('removes only an active seat', function () {
        expectOnlyFrom(AssignmentStatus::cases(), function ($w, AssignmentStatus $s) {
            $assignment = Assignment::factory()->status($s)->create(['project_id' => $w->project->id]);

            return app(RemoveAssignment::class)($assignment, $w->dm, 'Reason');
        }, [AssignmentStatus::Active]);
    });
});

describe('tasks (spec 6.7)', function () {
    it('starts only a todo task', function () {
        expectOnlyFrom(TaskStatus::cases(), function ($w, TaskStatus $s) {
            $w->task->forceFill(['status' => $s])->save();

            return app(StartTask::class)($w->task, $w->me);
        }, [TaskStatus::Todo]);
    });

    it('resumes only a task that needs changes', function () {
        expectOnlyFrom(TaskStatus::cases(), function ($w, TaskStatus $s) {
            $w->task->forceFill(['status' => $s])->save();

            return app(ResumeTask::class)($w->task, $w->me);
        }, [TaskStatus::ChangesRequested]);
    });

    it('submits only a task in progress', function () {
        expectOnlyFrom(TaskStatus::cases(), function ($w, TaskStatus $s) {
            $w->task->forceFill(['status' => $s])->save();

            return app(SubmitWork::class)($w->task, $w->me, 'Done');
        }, [TaskStatus::InProgress]);
    });

    it('assigns and deletes only a task that has not started', function (string $what) {
        expectOnlyFrom(TaskStatus::cases(), function ($w, TaskStatus $s) use ($what) {
            $w->task->forceFill(['status' => $s])->save();

            return $what === 'assign'
                ? app(AssignTask::class)($w->task, $w->dm, $w->seat->id)
                : app(DeleteTask::class)($w->task, $w->dm);
        }, [TaskStatus::Todo]);
    })->with(['assign', 'delete']);

    it('edits every task except an approved one', function () {
        expectOnlyFrom(TaskStatus::cases(), function ($w, TaskStatus $s) {
            $w->task->forceFill(['status' => $s])->save();

            return app(UpdateTask::class)($w->task, $w->dm, ['title' => 'Renamed']);
        }, array_values(array_filter(TaskStatus::cases(), fn (TaskStatus $s) => $s !== TaskStatus::Approved)));
    });
});

describe('submissions (spec 6.8)', function () {
    it('reviews a submission only when it and its task both await review', function (string $action) {
        $worked = [];

        foreach (SubmissionStatus::cases() as $submissionStatus) {
            foreach (TaskStatus::cases() as $taskStatus) {
                $w = matrix();
                $w->task->forceFill(['status' => $taskStatus])->save();
                $submission = Submission::factory()->status($submissionStatus)->create(['task_id' => $w->task->id, 'assignment_id' => $w->seat->id]);

                $ok = succeeds(fn () => $action === 'approve'
                    ? app(ApproveSubmission::class)($submission, $w->dm)
                    : app(RequestSubmissionChanges::class)($submission, $w->dm, 'Please fix'));

                if ($ok) {
                    $worked[] = "{$submissionStatus->value}|{$taskStatus->value}";
                }
            }
        }

        expect($worked)->toBe(['submitted|submitted']);
    })->with(['approve', 'request changes']);
});

describe('milestones (spec 6.9, SCR-015)', function () {
    it('shares only a milestone in QA', function () {
        expectOnlyFrom(MilestoneStatus::cases(), function ($w, MilestoneStatus $s) {
            $w->milestone->forceFill(['status' => $s])->save();
            $w->task->forceFill(['status' => TaskStatus::Approved])->save();
            $submission = Submission::factory()->status(SubmissionStatus::QaApproved)->create(['task_id' => $w->task->id, 'assignment_id' => $w->seat->id]);
            Deliverable::factory()->create(['milestone_id' => $w->milestone->id])->submissions()->attach($submission);

            return app(ShareMilestone::class)($w->milestone, $w->dm);
        }, [MilestoneStatus::InQa]);
    });

    it('lets the client answer only a shared milestone', function (string $action) {
        expectOnlyFrom(MilestoneStatus::cases(), function ($w, MilestoneStatus $s) use ($action) {
            $w->milestone->forceFill(['status' => $s])->save();

            return $action === 'accept'
                ? app(AcceptMilestone::class)($w->milestone, $w->owner)
                : app(RequestMilestoneChanges::class)($w->milestone, $w->owner, 'Please change');
        }, [MilestoneStatus::Shared]);
    })->with(['accept', 'request changes']);

    it('reopens only a milestone the client sent back', function () {
        expectOnlyFrom(MilestoneStatus::cases(), function ($w, MilestoneStatus $s) {
            $w->milestone->forceFill(['status' => $s])->save();

            return app(ReopenMilestone::class)($w->milestone, $w->dm);
        }, [MilestoneStatus::ClientChangesRequested]);
    });

    it('takes new tasks until the milestone is shared', function () {
        expectOnlyFrom(MilestoneStatus::cases(), function ($w, MilestoneStatus $s) {
            $w->milestone->forceFill(['status' => $s])->save();

            return app(CreateTask::class)($w->milestone, $w->dm, ['title' => 'Extra']);
        }, [MilestoneStatus::Planned, MilestoneStatus::InProgress, MilestoneStatus::InQa]);
    });

    it('builds deliverables only while the milestone is being worked', function () {
        expectOnlyFrom(MilestoneStatus::cases(), function ($w, MilestoneStatus $s) {
            $w->milestone->forceFill(['status' => $s])->save();
            $w->task->forceFill(['status' => TaskStatus::Approved])->save();
            $submission = Submission::factory()->status(SubmissionStatus::QaApproved)->create(['task_id' => $w->task->id, 'assignment_id' => $w->seat->id]);

            return app(CreateDeliverable::class)($w->milestone, $w->dm, ['title' => 'Bundle', 'submission_ids' => [$submission->id]]);
        }, [MilestoneStatus::InProgress, MilestoneStatus::InQa]);
    });
});

describe('projects (spec 6.2, SCR-016)', function () {
    it('allows each project Action from exactly its source states', function (string $action, array $expected) {
        expectOnlyFrom(ProjectStatus::cases(), function ($w, ProjectStatus $s) use ($action) {
            $w->project->forceFill(['status' => $s, 'status_before_hold' => $s === ProjectStatus::OnHold ? ProjectStatus::Active : null])->save();

            // Milestone facts the guards read: shared for delivery, accepted for acceptance.
            $w->milestone->forceFill(['status' => match ($action) {
                MarkProjectDelivered::class => MilestoneStatus::Shared,
                AcceptProject::class => MilestoneStatus::ClientAccepted,
                default => MilestoneStatus::Planned,
            }])->save();

            return match ($action) {
                PutProjectOnHold::class => app($action)($w->project, $w->dm, 'Waiting'),
                CancelProject::class => app($action)($w->project, $w->dm, 'Client withdrew'),
                ReopenProjectForChanges::class => app($action)($w->project, $w->owner),
                default => app($action)($w->project, $w->dm),
            };
        }, $expected);
    })->with([
        'start staffing' => [StartStaffing::class, [ProjectStatus::SowAccepted]],
        'activate' => [ActivateProject::class, [ProjectStatus::Staffing]],
        'move to QA' => [MoveProjectToQa::class, [ProjectStatus::Active]],
        'mark delivered' => [MarkProjectDelivered::class, [ProjectStatus::InQa]],
        'accept' => [AcceptProject::class, [ProjectStatus::Delivered]],
        'reopen for changes' => [ReopenProjectForChanges::class, [ProjectStatus::Delivered]],
        'close' => [CloseProject::class, [ProjectStatus::Accepted]],
        'put on hold' => [PutProjectOnHold::class, [ProjectStatus::Staffing, ProjectStatus::Active]],
        'resume' => [ResumeProject::class, [ProjectStatus::OnHold]],
        'cancel' => [CancelProject::class, [
            ProjectStatus::Draft, ProjectStatus::SowSent, ProjectStatus::SowAccepted, ProjectStatus::Staffing, ProjectStatus::Active,
            ProjectStatus::InQa, ProjectStatus::Delivered, ProjectStatus::Accepted, ProjectStatus::OnHold,
        ]],
    ]);
});
