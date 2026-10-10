<?php

use App\Actions\Submissions\ApproveSubmission;
use App\Actions\Submissions\RequestSubmissionChanges;
use App\Actions\Tasks\ResumeTask;
use App\Actions\Tasks\SubmitWork;
use App\Enums\AdminRole;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\ProjectStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Filament\Admin\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Admin\Resources\Submissions\Tables\SubmissionsTable;
use App\Livewire\Contributor\Work\TaskDetail;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Submission;
use App\Models\Task;
use App\Notifications\SubmissionReviewed;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');

    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $this->dm->id]);
    $this->me = contributorIn(OnboardingState::Active);
    $this->seat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->me->id]);
    $this->milestone = Milestone::factory()->status(MilestoneStatus::InQa)->create(['project_id' => $this->project->id]);
    $this->done = Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);
    $this->task = Task::factory()->status(TaskStatus::Submitted)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);
    $this->submission = Submission::factory()->create(['task_id' => $this->task->id, 'assignment_id' => $this->seat->id]);
});

describe('approve', function () {
    it('approves the submission and the task, keeps the milestone in QA and tells the contributor', function () {
        Notification::fake();

        $submission = app(ApproveSubmission::class)($this->submission, $this->dm, '  Well done  ');

        expect($submission->status)->toBe(SubmissionStatus::QaApproved)
            ->and($submission->qa_note)->toBe('Well done')
            ->and($submission->reviewed_by)->toBe($this->dm->id)
            ->and($submission->reviewed_at)->not->toBeNull()
            ->and($this->task->refresh()->status)->toBe(TaskStatus::Approved)
            ->and($this->task->approved_by)->toBe($this->dm->id)
            ->and($this->task->approved_at)->not->toBeNull()
            ->and($this->milestone->refresh()->status)->toBe(MilestoneStatus::InQa)
            ->and(Activity::query()->where('log_name', 'submission')->where('event', 'qa_approved')->count())->toBe(1)
            ->and(Activity::query()->where('log_name', 'task')->where('event', 'approved')->count())->toBe(1);

        Notification::assertSentTo($this->me, SubmissionReviewed::class, fn ($n) => $n->approved === true);
    });

    it('does not need a note', function () {
        expect(app(ApproveSubmission::class)($this->submission, $this->dm)->qa_note)->toBeNull();
    });
});

describe('request changes', function () {
    it('sends the task back, pulls the milestone out of QA and tells the contributor (SCR-015)', function () {
        Notification::fake();

        $submission = app(RequestSubmissionChanges::class)($this->submission, $this->dm, 'Totals do not add up');

        expect($submission->status)->toBe(SubmissionStatus::QaChangesRequested)
            ->and($submission->qa_note)->toBe('Totals do not add up')
            ->and($submission->reviewed_by)->toBe($this->dm->id)
            ->and($this->task->refresh()->status)->toBe(TaskStatus::ChangesRequested)
            ->and($this->task->approved_at)->toBeNull()
            ->and($this->done->refresh()->status)->toBe(TaskStatus::Approved)
            ->and($this->milestone->refresh()->status)->toBe(MilestoneStatus::InProgress);

        Notification::assertSentTo($this->me, SubmissionReviewed::class, fn ($n) => $n->approved === false && $n->submission->qa_note === 'Totals do not add up');
    });

    it('requires a note', function (string $note) {
        expect(fn () => app(RequestSubmissionChanges::class)($this->submission, $this->dm, $note))->toThrow(ActionBlocked::class, 'what needs to change');

        expect($this->submission->refresh()->status)->toBe(SubmissionStatus::Submitted)
            ->and($this->task->refresh()->status)->toBe(TaskStatus::Submitted);
    })->with(['', '   ']);

    it('runs the whole loop: changes, revision, version 2, approval', function () {
        app(RequestSubmissionChanges::class)($this->submission, $this->dm, 'Fix it');

        app(ResumeTask::class)($this->task->refresh(), $this->me);
        $second = app(SubmitWork::class)($this->task->refresh(), $this->me, 'Fixed');

        expect($second->version)->toBe(2)
            ->and($this->milestone->refresh()->status)->toBe(MilestoneStatus::InQa);

        app(ApproveSubmission::class)($second, $this->dm);

        expect($this->task->refresh()->status)->toBe(TaskStatus::Approved)
            ->and($this->submission->refresh()->status)->toBe(SubmissionStatus::QaChangesRequested)
            ->and($second->refresh()->status)->toBe(SubmissionStatus::QaApproved);
    });

    it('shows the note to the contributor on the task page', function () {
        app(RequestSubmissionChanges::class)($this->submission, $this->dm, 'Use the 2026 template');

        Livewire::actingAs($this->me)->test(TaskDetail::class, ['task' => $this->task->refresh()])
            ->assertSee('Use the 2026 template')
            ->assertSee('Start revising');
    });
});

describe('guards', function () {
    it('reviews a submission once', function (SubmissionStatus $status) {
        $this->submission->forceFill(['status' => $status])->save();

        expect(fn () => app(ApproveSubmission::class)($this->submission, $this->dm))->toThrow(IllegalTransition::class)
            ->and(fn () => app(RequestSubmissionChanges::class)($this->submission, $this->dm, 'x'))->toThrow(IllegalTransition::class);
    })->with([SubmissionStatus::QaApproved, SubmissionStatus::QaChangesRequested]);

    it('refuses when the task is not awaiting review', function (TaskStatus $status) {
        $this->task->forceFill(['status' => $status])->save();

        expect(fn () => app(ApproveSubmission::class)($this->submission, $this->dm))->toThrow(IllegalTransition::class);
        expect($this->submission->refresh()->status)->toBe(SubmissionStatus::Submitted);
    })->with([TaskStatus::InProgress, TaskStatus::Approved, TaskStatus::ChangesRequested]);

    it('reviews only while the project is active or in QA', function (ProjectStatus $status, bool $allowed) {
        $this->project->forceFill(['status' => $status])->save();

        if ($allowed) {
            expect(app(ApproveSubmission::class)($this->submission, $this->dm)->status)->toBe(SubmissionStatus::QaApproved);
        } else {
            expect(fn () => app(ApproveSubmission::class)($this->submission, $this->dm))->toThrow(ActionBlocked::class, 'active or in QA');
            expect($this->submission->refresh()->status)->toBe(SubmissionStatus::Submitted);
        }
    })->with([
        [ProjectStatus::Active, true],
        [ProjectStatus::InQa, true],
        [ProjectStatus::OnHold, false],
        [ProjectStatus::Delivered, false],
        [ProjectStatus::Closed, false],
        [ProjectStatus::Cancelled, false],
    ]);
});

describe('who may review', function () {
    it('allows delivery managers and super admins only', function (string $who, bool $allowed) {
        $actor = match ($who) {
            'delivery_manager' => $this->dm,
            'super_admin' => adminUser(AdminRole::SuperAdmin),
            'community_manager' => adminUser(AdminRole::CommunityManager),
            'finance_ops' => adminUser(AdminRole::FinanceOps),
            'submitter' => $this->me,
            'other_contributor' => contributorIn(OnboardingState::Active),
            'client' => clientUser($this->project->organization),
        };

        if ($allowed) {
            expect(app(ApproveSubmission::class)($this->submission, $actor)->status)->toBe(SubmissionStatus::QaApproved);

            return;
        }

        expect(fn () => app(ApproveSubmission::class)($this->submission, $actor))->toThrow(AuthorizationException::class)
            ->and(fn () => app(RequestSubmissionChanges::class)($this->submission, $actor, 'x'))->toThrow(AuthorizationException::class);
        expect($this->submission->refresh()->status)->toBe(SubmissionStatus::Submitted);
    })->with([
        ['delivery_manager', true],
        ['super_admin', true],
        ['community_manager', false],
        ['finance_ops', false],
        ['submitter', false],
        ['other_contributor', false],
        ['client', false],
    ]);

    it('lets a contributor read their own submissions and nobody else\'s, and keeps clients out', function () {
        $other = contributorIn(OnboardingState::Active);
        $client = clientUser($this->project->organization);

        expect(Gate::forUser($this->me)->allows('view', $this->submission))->toBeTrue()
            ->and(Gate::forUser($other)->allows('view', $this->submission))->toBeFalse()
            ->and(Gate::forUser($client)->allows('view', $this->submission))->toBeFalse()
            ->and(Gate::forUser($client)->allows('viewAny', Submission::class))->toBeFalse()
            ->and(Gate::forUser($this->dm)->allows('view', $this->submission))->toBeTrue()
            ->and(Gate::forUser(adminUser(AdminRole::FinanceOps))->allows('view', $this->submission))->toBeFalse();
    });
});

describe('qa queue screen', function () {
    beforeEach(function () {
        $this->changes = Submission::factory()->status(SubmissionStatus::QaChangesRequested)->create(['task_id' => Task::factory()->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id])->id, 'assignment_id' => $this->seat->id]);
        $this->approved = Submission::factory()->status(SubmissionStatus::QaApproved)->create(['task_id' => Task::factory()->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id])->id, 'assignment_id' => $this->seat->id]);
    });

    it('opens on the submissions awaiting review and offers the other states as tabs', function () {
        $page = Livewire::actingAs($this->dm)->test(ListSubmissions::class)
            ->assertCanSeeTableRecords([$this->submission])
            ->assertCanNotSeeTableRecords([$this->changes, $this->approved]);

        $page->set('activeTab', 'changes')->assertCanSeeTableRecords([$this->changes])->assertCanNotSeeTableRecords([$this->submission]);
        $page->set('activeTab', 'approved')->assertCanSeeTableRecords([$this->approved])->assertCanNotSeeTableRecords([$this->submission]);
        $page->set('activeTab', 'all')->assertCanSeeTableRecords([$this->submission, $this->changes, $this->approved]);
    });

    it('approves and requests changes from the queue', function () {
        $second = Submission::factory()->create(['task_id' => Task::factory()->status(TaskStatus::Submitted)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id])->id, 'assignment_id' => $this->seat->id]);

        $page = Livewire::actingAs($this->dm)->test(ListSubmissions::class);

        $page->callAction(TestAction::make('approve')->table($this->submission), ['note' => 'Great'])->assertHasNoActionErrors();
        $page->callAction(TestAction::make('requestChanges')->table($second), ['note' => 'Redo section 2'])->assertHasNoActionErrors();

        expect($this->submission->refresh()->status)->toBe(SubmissionStatus::QaApproved)
            ->and($second->refresh()->status)->toBe(SubmissionStatus::QaChangesRequested)
            ->and($second->qa_note)->toBe('Redo section 2');
    });

    it('will not submit a change request without a note', function () {
        Livewire::actingAs($this->dm)->test(ListSubmissions::class)
            ->callAction(TestAction::make('requestChanges')->table($this->submission), ['note' => ''])
            ->assertHasActionErrors(['note' => 'required']);

        expect($this->submission->refresh()->status)->toBe(SubmissionStatus::Submitted);
    });

    it('offers the review actions only on submissions awaiting review', function () {
        Livewire::actingAs($this->dm)->test(ListSubmissions::class)->set('activeTab', 'all')
            ->assertActionVisible(TestAction::make('approve')->table($this->submission))
            ->assertActionVisible(TestAction::make('requestChanges')->table($this->submission))
            ->assertActionHidden(TestAction::make('approve')->table($this->changes))
            ->assertActionHidden(TestAction::make('requestChanges')->table($this->approved))
            ->assertActionVisible(TestAction::make('inspect')->table($this->approved));
    });

    it('is closed to roles without submission access', function (string $who) {
        $actor = match ($who) {
            'community_manager' => adminUser(AdminRole::CommunityManager),
            'finance_ops' => adminUser(AdminRole::FinanceOps),
            'contributor' => $this->me,
            'client' => clientUser($this->project->organization),
        };

        Livewire::actingAs($actor)->test(ListSubmissions::class)->assertForbidden();
    })->with(['community_manager', 'finance_ops', 'contributor', 'client']);

    it('escapes contributor text in the review modal', function () {
        $this->submission->forceFill(['note' => '<script>alert(1)</script>', 'qa_note' => '<img src=x onerror=alert(2)>'])->save();
        $this->task->forceFill(['description' => '<b>bold</b>'])->save();

        $html = SubmissionsTable::summary($this->submission->load('task', 'media'));

        expect($html)->not->toContain('<script>')
            ->not->toContain('<img')
            ->not->toContain('<b>bold')
            ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    });
});
