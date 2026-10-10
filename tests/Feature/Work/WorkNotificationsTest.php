<?php

use App\Actions\Assignments\AcceptAssignment;
use App\Actions\Assignments\DeclineAssignment;
use App\Actions\Assignments\InviteContributor;
use App\Actions\Assignments\RemoveAssignment;
use App\Actions\Milestones\AcceptMilestone;
use App\Actions\Milestones\ReopenMilestone;
use App\Actions\Milestones\RequestMilestoneChanges;
use App\Actions\Milestones\ShareMilestone;
use App\Actions\Milestones\SyncMilestoneStatus;
use App\Actions\Projects\AcceptProject;
use App\Actions\Projects\MarkProjectDelivered;
use App\Actions\Projects\ReopenProjectForChanges;
use App\Actions\Submissions\ApproveSubmission;
use App\Actions\Submissions\RequestSubmissionChanges;
use App\Actions\Tasks\AddTaskComment;
use App\Actions\Tasks\AssignTask;
use App\Actions\Tasks\ResumeTask;
use App\Actions\Tasks\StartTask;
use App\Actions\Tasks\SubmitWork;
use App\Enums\AdminRole;
use App\Enums\AssignmentStatus;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Models\Assignment;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Submission;
use App\Models\Task;
use App\Notifications\AssignmentInvited;
use App\Notifications\AssignmentRemoved;
use App\Notifications\AssignmentResponded;
use App\Notifications\DeliverableShared;
use App\Notifications\GroibeNotification;
use App\Notifications\MilestoneReadyToShare;
use App\Notifications\MilestoneResponded;
use App\Notifications\ProjectAccepted;
use App\Notifications\ProjectDelivered;
use App\Notifications\SubmissionReceived;
use App\Notifications\SubmissionReviewed;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskChanged;
use App\Notifications\TasksOverdue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();

    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $this->dm->id]);
    $this->owner = clientUser($this->project->organization, OrgRole::Owner);
    $this->manager = clientUser($this->project->organization, OrgRole::Manager);
    $this->viewer = clientUser($this->project->organization, OrgRole::Viewer);
    $this->me = contributorIn(OnboardingState::Active);
    $this->seat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->me->id]);
    $this->milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
});

/** Everyone who could plausibly be told something about this project, to prove who is and is not. */
function bystanders(): array
{
    return [test()->viewer, adminUser(AdminRole::FinanceOps), adminUser(AdminRole::CommunityManager), contributorIn(OnboardingState::Active), clientUser()];
}

describe('transitions that tell someone', function () {
    it('tells the delivery manager when a milestone reaches QA, and nobody else', function () {
        $first = Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);
        $last = Task::factory()->status(TaskStatus::InProgress)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);
        $this->milestone->forceFill(['status' => MilestoneStatus::InProgress])->save();

        app(SubmitWork::class)($last, $this->me, 'Done');

        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::InQa);
        Notification::assertSentToTimes($this->dm, MilestoneReadyToShare::class, 1);
        Notification::assertNotSentTo([$this->me, $this->owner, $this->manager, ...bystanders()], MilestoneReadyToShare::class);
    });

    it('tells the delivery manager once, whichever route brings the milestone to QA', function () {
        Task::factory()->status(TaskStatus::Submitted)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);
        $this->milestone->forceFill(['status' => MilestoneStatus::InProgress])->save();

        app(SyncMilestoneStatus::class)($this->milestone);
        app(SyncMilestoneStatus::class)($this->milestone);

        Notification::assertSentToTimes($this->dm, MilestoneReadyToShare::class, 1);
    });

    it('tells the client managers when the project is delivered, not viewers', function () {
        $this->project->forceFill(['status' => ProjectStatus::InQa])->save();
        $this->milestone->forceFill(['status' => MilestoneStatus::Shared])->save();

        app(MarkProjectDelivered::class)($this->project, $this->dm);

        Notification::assertSentTo([$this->owner, $this->manager], ProjectDelivered::class);
        Notification::assertNotSentTo([$this->viewer, $this->me, ...bystanders()], ProjectDelivered::class);
        Notification::assertNotSentTo($this->dm, ProjectDelivered::class);
    });

    it('tells the delivery manager and completed contributors when the project is accepted', function () {
        $this->project->forceFill(['status' => ProjectStatus::Delivered])->save();
        $this->milestone->forceFill(['status' => MilestoneStatus::ClientAccepted])->save();
        $removed = Assignment::factory()->status(AssignmentStatus::Removed)->create(['project_id' => $this->project->id]);
        $invited = Assignment::factory()->create(['project_id' => $this->project->id]);
        $declined = Assignment::factory()->status(AssignmentStatus::Declined)->create(['project_id' => $this->project->id]);

        app(AcceptProject::class)($this->project, $this->dm);

        Notification::assertSentTo([$this->dm, $this->me], ProjectAccepted::class);
        Notification::assertNotSentTo([$removed->user, $invited->user, $declined->user, $this->owner, $this->viewer, ...bystanders()], ProjectAccepted::class);
    });

    it('does not tell the client or contributors anything about rates or the client when accepted', function () {
        $this->seat->forceFill(['agreed_amount' => 876543])->save();
        $project = $this->project;

        $notification = new ProjectAccepted($project);

        expect(json_encode($notification->toArray($this->me)))->not->toContain('876543')->not->toContain($project->organization->name)
            ->and($notification->url($this->me))->toContain($project->uuid)->not->toContain('/admin')
            ->and($notification->url($this->dm))->toContain('/admin');
    });

    it('covers the earlier assignment, task, submission and deliverable events', function () {
        $staffing = Project::factory()->status(ProjectStatus::Staffing)->create(['delivery_manager_id' => $this->dm->id]);
        $newcomer = contributorIn(OnboardingState::Active);
        $seat = app(InviteContributor::class)($staffing, $newcomer, $this->dm, ['role_title' => 'Analyst']);
        Notification::assertSentTo($newcomer, AssignmentInvited::class);

        app(AcceptAssignment::class)($seat, $newcomer);
        Notification::assertSentTo($this->dm, AssignmentResponded::class, fn ($n) => $n->accepted);

        $second = app(InviteContributor::class)($staffing, contributorIn(OnboardingState::Active), $this->dm, ['role_title' => 'Writer']);
        app(DeclineAssignment::class)($second, $second->user);
        Notification::assertSentTo($this->dm, AssignmentResponded::class, fn ($n) => ! $n->accepted);

        app(RemoveAssignment::class)($seat->refresh(), $this->dm, 'Left');
        Notification::assertSentTo([$newcomer, $this->dm], AssignmentRemoved::class);

        $task = Task::factory()->create(['milestone_id' => $this->milestone->id]);
        app(AssignTask::class)($task, $this->dm, $this->seat->id);
        Notification::assertSentTo($this->me, TaskAssigned::class);

        $other = Assignment::factory()->active()->create(['project_id' => $this->project->id]);
        app(AssignTask::class)($task->refresh(), $this->dm, $other->id);
        Notification::assertSentTo($this->me, TaskChanged::class);
        Notification::assertSentTo($other->user, TaskAssigned::class);
    });

    it('covers submission and QA results', function () {
        $task = Task::factory()->status(TaskStatus::InProgress)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);
        Task::factory()->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);

        $submission = app(SubmitWork::class)($task, $this->me, 'v1');
        Notification::assertSentTo($this->dm, SubmissionReceived::class);

        app(RequestSubmissionChanges::class)($submission, $this->dm, 'Redo');
        Notification::assertSentTo($this->me, SubmissionReviewed::class, fn ($n) => ! $n->approved);

        app(ResumeTask::class)($task->refresh(), $this->me);
        $second = app(SubmitWork::class)($task->refresh(), $this->me, 'v2');
        app(ApproveSubmission::class)($second, $this->dm);
        Notification::assertSentTo($this->me, SubmissionReviewed::class, fn ($n) => $n->approved);
    });

    it('covers sharing and the client answers', function () {
        $milestone = Milestone::factory()->status(MilestoneStatus::InQa)->create(['project_id' => $this->project->id]);
        $task = Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $milestone->id, 'assignment_id' => $this->seat->id]);
        $submission = Submission::factory()->status(SubmissionStatus::QaApproved)->create(['task_id' => $task->id, 'assignment_id' => $this->seat->id]);
        Deliverable::factory()->create(['milestone_id' => $milestone->id])->submissions()->attach($submission);

        app(ShareMilestone::class)($milestone, $this->dm);
        Notification::assertSentTo([$this->owner, $this->manager], DeliverableShared::class);
        Notification::assertNotSentTo([$this->viewer, $this->me, ...bystanders()], DeliverableShared::class);

        app(RequestMilestoneChanges::class)($milestone->refresh(), $this->owner, 'Change it');
        Notification::assertSentTo($this->dm, MilestoneResponded::class, fn ($n) => ! $n->accepted);

        app(ReopenMilestone::class)($milestone->refresh(), $this->dm);
        app(ShareMilestone::class)($milestone->refresh(), $this->dm);
        app(AcceptMilestone::class)($milestone->refresh(), $this->manager);
        Notification::assertSentTo($this->dm, MilestoneResponded::class, fn ($n) => $n->accepted);
    });
});

describe('transitions that stay quiet by design', function () {
    it('says nothing when a task is started, resumed or commented on', function () {
        $todo = Task::factory()->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);
        $revise = Task::factory()->status(TaskStatus::ChangesRequested)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);

        app(StartTask::class)($todo, $this->me);
        app(ResumeTask::class)($revise, $this->me);
        app(AddTaskComment::class)($todo, $this->me, 'A question');

        Notification::assertNothingSent();
    });

    it('says nothing when a milestone starts, is reopened with work pending, or a delivered project returns to QA', function () {
        $this->milestone->forceFill(['status' => MilestoneStatus::ClientChangesRequested])->save();
        Task::factory()->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);
        app(ReopenMilestone::class)($this->milestone, $this->dm);

        $delivered = Project::factory()->status(ProjectStatus::Delivered)->create();
        app(ReopenProjectForChanges::class)($delivered, clientUser($delivered->organization));

        Notification::assertNothingSent();
    });
});

describe('every notification', function () {
    $cases = function () {
        $dm = test()->dm;
        $project = test()->project;
        $milestone = test()->milestone;
        $task = Task::factory()->create(['milestone_id' => $milestone->id, 'assignment_id' => test()->seat->id, 'due_date' => '2026-12-01']);
        $submission = Submission::factory()->create(['task_id' => $task->id, 'assignment_id' => test()->seat->id, 'qa_note' => 'Check the totals']);
        $seat = test()->seat;
        $milestone->forceFill(['client_note' => 'Please add a chart'])->save();

        return [
            'invited' => [new AssignmentInvited($seat, $project), test()->me],
            'accepted' => [new AssignmentResponded($seat, $project, 'Priya', true), $dm],
            'declined' => [new AssignmentResponded($seat, $project, 'Priya', false), $dm],
            'removed (contributor)' => [new AssignmentRemoved($seat, $project, 'Priya', 2), test()->me],
            'removed (admin)' => [new AssignmentRemoved($seat, $project, 'Priya', 2), $dm],
            'task assigned' => [new TaskAssigned($task, $project), test()->me],
            'task changed' => [new TaskChanged($task, $project, 'The task details were updated.'), test()->me],
            'submission received' => [new SubmissionReceived($submission, $task, $project, 'Priya'), $dm],
            'approved' => [new SubmissionReviewed($submission, $task, $project, true), test()->me],
            'changes requested' => [new SubmissionReviewed($submission, $task, $project, false), test()->me],
            'ready to share' => [new MilestoneReadyToShare($milestone, $project), $dm],
            'shared' => [new DeliverableShared($milestone, $project), test()->owner],
            'client accepted' => [new MilestoneResponded($milestone, $project, 'Anita', true), $dm],
            'client changes' => [new MilestoneResponded($milestone, $project, 'Anita', false), $dm],
            'delivered' => [new ProjectDelivered($project), test()->owner],
            'accepted (admin)' => [new ProjectAccepted($project), $dm],
            'accepted (contributor)' => [new ProjectAccepted($project), test()->me],
            'overdue' => [new TasksOverdue($project, 1, [['title' => 'T', 'due' => 'Dec 1, 2026', 'status' => 'Todo', 'assignee' => 'Unassigned']]), $dm],
        ];
    };

    it('renders its mail and database message, in a known category, queued', function () use ($cases) {
        foreach ($cases() as $label => [$notification, $user]) {
            $mail = $notification->toMail($user);
            $data = $notification->toArray($user);

            expect($notification)->toBeInstanceOf(GroibeNotification::class)->toBeInstanceOf(ShouldQueue::class);
            expect($mail->subject)->not->toBeEmpty("{$label}: subject");
            expect((string) $mail->render())->toContain('Hello')->not->toContain('{{', "{$label}: body");
            expect(config('groibe.notifications.categories'))->toContain($notification->category());
            expect($data)->toHaveKeys(['category', 'title', 'body', 'url'], $label);
            expect($data['url'])->toStartWith('http')->and($data['category'])->toBe($notification->category());
            expect($notification->via($user))->toBe(['database', 'mail']);
        }
    });

    it('never puts internal notes or rates in a message that goes to a client or contributor', function () use ($cases) {
        test()->seat->forceFill(['agreed_amount' => 876543])->save();

        foreach ($cases() as $label => [$notification, $user]) {
            if ($user->isAdmin()) {
                continue;
            }

            $text = json_encode($notification->toArray($user)).$notification->toMail($user)->render();

            expect($text)->not->toContain('876543', $label);

            if ($user->isClient()) {
                expect($text)->not->toContain('Check the totals', $label);
            }
        }
    });
});
