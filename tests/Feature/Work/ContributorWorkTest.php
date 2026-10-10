<?php

use App\Actions\Tasks\AddTaskComment;
use App\Actions\Tasks\ResumeTask;
use App\Actions\Tasks\StartTask;
use App\Actions\Tasks\SubmitWork;
use App\Enums\AdminRole;
use App\Enums\AssignmentStatus;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\ProjectStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Livewire\Contributor\Work\MyWork;
use App\Livewire\Contributor\Work\ProjectBoard;
use App\Livewire\Contributor\Work\TaskDetail;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskComment;
use App\Notifications\SubmissionReceived;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');

    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $this->dm->id]);
    $this->me = contributorIn(OnboardingState::Active);
    $this->seat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->me->id]);
    $this->milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
    $this->task = Task::factory()->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);
});

function pdf(string $name = 'report.pdf', int $kb = 100): UploadedFile
{
    return UploadedFile::fake()->create($name, $kb, 'application/pdf');
}

describe('starting and resuming', function () {
    it('starts a task, logs it and starts the milestone', function () {
        $task = app(StartTask::class)($this->task, $this->me);

        expect($task->status)->toBe(TaskStatus::InProgress)
            ->and($this->milestone->refresh()->status)->toBe(MilestoneStatus::InProgress)
            ->and(Activity::query()->where('log_name', 'task')->where('event', 'in_progress')->count())->toBe(1);
    });

    it('only starts a todo task and only resumes one that needs changes', function () {
        $started = app(StartTask::class)($this->task, $this->me);

        expect(fn () => app(StartTask::class)($started, $this->me))->toThrow(IllegalTransition::class)
            ->and(fn () => app(ResumeTask::class)($started, $this->me))->toThrow(IllegalTransition::class);

        $started->forceFill(['status' => TaskStatus::ChangesRequested])->save();

        expect(fn () => app(StartTask::class)($started, $this->me))->toThrow(IllegalTransition::class)
            ->and(app(ResumeTask::class)($started, $this->me)->status)->toBe(TaskStatus::InProgress);
    });

    it('is refused to everyone but the assignee', function (string $who) {
        $actor = match ($who) {
            'other_contributor' => contributorIn(OnboardingState::Active),
            'admin' => $this->dm,
            'client' => clientUser($this->project->organization),
            'suspended_assignee' => contributorIn(OnboardingState::Suspended),
        };

        if ($who === 'suspended_assignee') {
            $this->seat->forceFill(['user_id' => $actor->id])->save();
        }

        expect(fn () => app(StartTask::class)($this->task, $actor))->toThrow(AuthorizationException::class);
        expect($this->task->refresh()->status)->toBe(TaskStatus::Todo);
    })->with(['other_contributor', 'admin', 'client', 'suspended_assignee']);

    it('is refused on an unassigned task and on a seat that is not active', function () {
        $loose = Task::factory()->create(['milestone_id' => $this->milestone->id]);

        expect(fn () => app(StartTask::class)($loose, $this->me))->toThrow(AuthorizationException::class);

        foreach ([AssignmentStatus::Completed, AssignmentStatus::Removed, AssignmentStatus::Invited] as $status) {
            $this->seat->forceFill(['status' => $status])->save();
            expect(fn () => app(StartTask::class)($this->task, $this->me))->toThrow(AuthorizationException::class);
        }
    });

    it('only works while the project is active or in QA', function (ProjectStatus $status, bool $allowed) {
        $this->project->forceFill(['status' => $status])->save();

        if ($allowed) {
            expect(app(StartTask::class)($this->task, $this->me)->status)->toBe(TaskStatus::InProgress);
        } else {
            expect(fn () => app(StartTask::class)($this->task, $this->me))->toThrow(ActionBlocked::class, 'project is active');
        }
    })->with([
        [ProjectStatus::Active, true],
        [ProjectStatus::InQa, true],
        [ProjectStatus::Staffing, false],
        [ProjectStatus::OnHold, false],
        [ProjectStatus::Delivered, false],
        [ProjectStatus::Closed, false],
        [ProjectStatus::Cancelled, false],
    ]);
});

describe('submitting work', function () {
    beforeEach(function () {
        $this->task->forceFill(['status' => TaskStatus::InProgress])->save();
        $this->milestone->forceFill(['status' => MilestoneStatus::InProgress])->save();
    });

    it('creates version 1 with private randomised files, submits the task and tells the delivery manager', function () {
        Notification::fake();

        $submission = app(SubmitWork::class)($this->task, $this->me, 'First draft', [pdf('survey.pdf'), pdf('data.pdf')]);

        $media = $submission->getMedia(Submission::FILES);

        expect($submission->version)->toBe(1)
            ->and($submission->status)->toBe(SubmissionStatus::Submitted)
            ->and($submission->user_id)->toBe($this->me->id)
            ->and($submission->assignment_id)->toBe($this->seat->id)
            ->and($submission->note)->toBe('First draft')
            ->and($media)->toHaveCount(2)
            ->and($media->first()->disk)->toBe('local')
            ->and($media->first()->file_name)->not->toContain('survey')
            ->and($media->pluck('name')->sort()->values()->all())->toBe(['data', 'survey'])
            ->and($this->task->refresh()->status)->toBe(TaskStatus::Submitted)
            ->and(Activity::query()->where('log_name', 'submission')->where('event', 'submitted')->count())->toBe(1);

        Notification::assertSentTo($this->dm, SubmissionReceived::class);
    });

    it('moves the milestone to QA when its last task is submitted', function () {
        Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $this->milestone->id]);

        app(SubmitWork::class)($this->task, $this->me, 'Done');

        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::InQa);
    });

    it('takes a note without files, or files without a note, but not neither', function () {
        expect(fn () => app(SubmitWork::class)($this->task, $this->me, '  ', []))->toThrow(ActionBlocked::class, 'note or at least one file');

        app(SubmitWork::class)($this->task, $this->me, null, [pdf()]);
        expect($this->task->refresh()->status)->toBe(TaskStatus::Submitted);

        $second = Task::factory()->status(TaskStatus::InProgress)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);
        app(SubmitWork::class)($second, $this->me, 'Just a note');
        expect(Submission::query()->count())->toBe(2);
    });

    it('numbers a resubmission after changes as the next version', function () {
        $first = app(SubmitWork::class)($this->task, $this->me, 'v1');
        $first->forceFill(['status' => SubmissionStatus::QaChangesRequested, 'qa_note' => 'Fix the totals'])->save();
        $this->task->refresh()->forceFill(['status' => TaskStatus::ChangesRequested])->save();

        app(ResumeTask::class)($this->task, $this->me);
        $second = app(SubmitWork::class)($this->task, $this->me, 'v2');

        expect($second->version)->toBe(2)
            ->and($first->refresh()->version)->toBe(1)
            ->and($first->status)->toBe(SubmissionStatus::QaChangesRequested)
            ->and($this->task->submissions()->count())->toBe(2)
            ->and($this->task->refresh()->status)->toBe(TaskStatus::Submitted);
    });

    it('rejects disallowed, oversized and too many files and leaves everything untouched', function (string $case) {
        $files = match ($case) {
            'executable' => [UploadedFile::fake()->create('setup.exe', 10, 'application/x-msdownload')],
            'script' => [UploadedFile::fake()->create('run.php', 1, 'text/x-php')],
            'disguised' => [UploadedFile::fake()->create('photo.png', 10, 'application/x-msdownload')],
            'too_big' => [pdf('big.pdf', 25 * 1024 + 1)],
            'second_file_bad' => [pdf(), UploadedFile::fake()->create('setup.exe', 10)],
        };

        expect(fn () => app(SubmitWork::class)($this->task, $this->me, 'x', $files))->toThrow(ValidationException::class);

        expect(Submission::query()->count())->toBe(0)
            ->and($this->task->refresh()->status)->toBe(TaskStatus::InProgress);
    })->with(['executable', 'script', 'disguised', 'too_big', 'second_file_bad']);

    it('accepts a file right at the size limit', function () {
        expect(app(SubmitWork::class)($this->task, $this->me, null, [pdf('limit.pdf', 25 * 1024)])->getMedia(Submission::FILES))->toHaveCount(1);
    });

    it('limits the number of files', function () {
        $files = array_map(fn ($n) => pdf("f{$n}.pdf", 1), range(1, 11));

        expect(fn () => app(SubmitWork::class)($this->task, $this->me, 'x', $files))->toThrow(ActionBlocked::class, 'up to 10 files');
    });

    it('is refused unless the task is in progress', function (TaskStatus $status) {
        $this->task->forceFill(['status' => $status])->save();

        expect(fn () => app(SubmitWork::class)($this->task, $this->me, 'x'))->toThrow(IllegalTransition::class);
        expect(Submission::query()->count())->toBe(0);
    })->with([TaskStatus::Todo, TaskStatus::Submitted, TaskStatus::Approved, TaskStatus::ChangesRequested]);

    it('is refused to everyone but the assignee', function (string $who) {
        $actor = match ($who) {
            'other_contributor' => contributorIn(OnboardingState::Active),
            'admin' => $this->dm,
            'client' => clientUser($this->project->organization),
        };

        expect(fn () => app(SubmitWork::class)($this->task, $actor, 'x'))->toThrow(AuthorizationException::class);
        expect(Submission::query()->count())->toBe(0);
    })->with(['other_contributor', 'admin', 'client']);

    it('is refused once the project is on hold', function () {
        $this->project->forceFill(['status' => ProjectStatus::OnHold])->save();

        expect(fn () => app(SubmitWork::class)($this->task, $this->me, 'x'))->toThrow(ActionBlocked::class);
        expect(Submission::query()->count())->toBe(0);
    });
});

describe('comments', function () {
    it('lets the assignee comment, never internally', function () {
        $comment = app(AddTaskComment::class)($this->task, $this->me, '  Question about scope  ');

        expect($comment->body)->toBe('Question about scope')
            ->and($comment->is_internal)->toBeFalse()
            ->and($comment->user_id)->toBe($this->me->id);
    });

    it('refuses empty comments and comments from anyone else', function () {
        expect(fn () => app(AddTaskComment::class)($this->task, $this->me, '   '))->toThrow(ActionBlocked::class);

        foreach ([contributorIn(OnboardingState::Active), $this->dm, clientUser($this->project->organization)] as $actor) {
            expect(fn () => app(AddTaskComment::class)($this->task, $actor, 'hello'))->toThrow(AuthorizationException::class);
        }

        expect(TaskComment::query()->count())->toBe(0);
    });
});

describe('route access', function () {
    it('keeps every work page behind sign-in, the contributor role and active status', function (string $page) {
        $url = match ($page) {
            'list' => route('contributor.work'),
            'board' => route('contributor.work.project', $this->project),
            'task' => route('contributor.work.task', $this->task),
        };

        $this->get($url)->assertRedirect(route('login'));

        asUser(clientUser($this->project->organization));
        $this->get($url)->assertForbidden();

        asUser($this->dm);
        $this->get($url)->assertForbidden();

        asUser(contributorIn(OnboardingState::PendingReview));
        $this->get($url)->assertForbidden();

        asUser(contributorIn(OnboardingState::Suspended));
        $this->get($url)->assertForbidden();

        asUser($this->me);
        $this->get($url)->assertOk();
    })->with(['list', 'board', 'task']);

    it('answers 404, not 403, for projects and tasks that are not yours', function () {
        $stranger = contributorIn(OnboardingState::Active);
        $invitedOnly = contributorIn(OnboardingState::Active);
        Assignment::factory()->create(['project_id' => $this->project->id, 'user_id' => $invitedOnly->id]);

        foreach ([$stranger, $invitedOnly] as $person) {
            asUser($person);
            $this->get(route('contributor.work.project', $this->project))->assertNotFound();
            $this->get(route('contributor.work.task', $this->task))->assertNotFound();
        }
    });
});

describe('my work page', function () {
    it('lists only my seats and hides declined and removed ones', function () {
        $other = Assignment::factory()->active()->create();
        $declined = Assignment::factory()->status(AssignmentStatus::Declined)->create(['user_id' => $this->me->id]);
        $invited = Assignment::factory()->create(['user_id' => $this->me->id]);

        Livewire::actingAs($this->me)->test(MyWork::class)
            ->assertSee($this->project->title)
            ->assertSee($invited->project->title)
            ->assertDontSee($other->project->title)
            ->assertDontSee($declined->project->title);
    });

    it('shows no rates and no client details', function () {
        $this->seat->forceFill(['agreed_amount' => 987654])->save();

        Livewire::actingAs($this->me)->test(MyWork::class)
            ->assertDontSee('987654')
            ->assertDontSee($this->project->organization->name);
    });

    it('accepts and declines invites and refuses to answer someone else\'s', function () {
        $staffing = Project::factory()->status(ProjectStatus::Staffing)->create();
        $accept = Assignment::factory()->create(['user_id' => $this->me->id, 'project_id' => $staffing->id]);
        $decline = Assignment::factory()->create(['user_id' => $this->me->id, 'project_id' => $staffing->id]);
        $notMine = Assignment::factory()->create(['project_id' => $staffing->id]);

        $page = Livewire::actingAs($this->me)->test(MyWork::class);
        $page->call('accept', $accept->id)->call('decline', $decline->id);

        expect($accept->refresh()->status)->toBe(AssignmentStatus::Active)
            ->and($decline->refresh()->status)->toBe(AssignmentStatus::Declined);

        $page->call('accept', $notMine->id)->assertNotFound();
        expect($notMine->refresh()->status)->toBe(AssignmentStatus::Invited);
    });

    it('cannot accept after the project has moved on', function () {
        $late = Assignment::factory()->create(['user_id' => $this->me->id, 'project_id' => Project::factory()->status(ProjectStatus::OnHold)->create()->id]);

        Livewire::actingAs($this->me)->test(MyWork::class)->call('accept', $late->id);

        expect($late->refresh()->status)->toBe(AssignmentStatus::Invited);
    });
});

describe('task board', function () {
    it('groups my tasks into columns and leaves out other people\'s tasks', function () {
        $mine = fn (TaskStatus $s, string $title) => Task::factory()->status($s)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id, 'title' => $title]);
        $mine(TaskStatus::InProgress, 'Working task');
        $mine(TaskStatus::ChangesRequested, 'Revise task');
        $mine(TaskStatus::Approved, 'Finished task');
        $teammate = Assignment::factory()->active()->create(['project_id' => $this->project->id]);
        Task::factory()->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $teammate->id, 'title' => 'Teammate task']);
        Task::factory()->create(['milestone_id' => $this->milestone->id, 'title' => 'Unassigned task']);

        $page = Livewire::actingAs($this->me)->test(ProjectBoard::class, ['project' => $this->project]);

        $page->assertSee($this->task->title)->assertSee('Working task')->assertSee('Revise task')->assertSee('Finished task')
            ->assertDontSee('Teammate task')->assertDontSee('Unassigned task');

        $columns = $page->viewData('columns');
        expect($columns['To do'])->toHaveCount(1)
            ->and($columns['In progress'])->toHaveCount(2)
            ->and($columns['Submitted'])->toHaveCount(0)
            ->and($columns['Approved'])->toHaveCount(1);
    });

    it('stays available after the project is accepted', function () {
        $this->seat->forceFill(['status' => AssignmentStatus::Completed])->save();

        Livewire::actingAs($this->me)->test(ProjectBoard::class, ['project' => $this->project])->assertOk();
    });
});

describe('task page', function () {
    it('walks a contributor through start, submit, changes and resubmission', function () {
        $page = Livewire::actingAs($this->me)->test(TaskDetail::class, ['task' => $this->task])
            ->assertSee('Start task')
            ->call('start')
            ->assertSee('Submit work');

        $page->set('note', 'First attempt')->set('uploads', [pdf('draft.pdf')])->call('submit')->assertHasNoErrors();

        expect($this->task->refresh()->status)->toBe(TaskStatus::Submitted);
        $page->assertSee('Version 1')->assertSee('draft.pdf')->assertSee('First attempt')->assertSee('Groibe is reviewing it');

        Submission::query()->sole()->forceFill(['status' => SubmissionStatus::QaChangesRequested, 'qa_note' => 'Totals do not add up'])->save();
        $this->task->forceFill(['status' => TaskStatus::ChangesRequested])->save();

        $page = Livewire::actingAs($this->me)->test(TaskDetail::class, ['task' => $this->task->fresh()])
            ->assertSee('Totals do not add up')
            ->assertSee('Start revising')
            ->call('resume')
            ->set('note', 'Fixed')
            ->call('submit');

        expect(Submission::query()->orderBy('version')->pluck('version')->all())->toBe([1, 2])
            ->and($this->task->refresh()->status)->toBe(TaskStatus::Submitted);
    });

    it('shows upload errors and keeps the work in progress', function () {
        $this->task->forceFill(['status' => TaskStatus::InProgress])->save();

        Livewire::actingAs($this->me)->test(TaskDetail::class, ['task' => $this->task])
            ->set('uploads', [UploadedFile::fake()->create('setup.exe', 10)])
            ->call('submit')
            ->assertHasErrors('uploads');

        expect($this->task->refresh()->status)->toBe(TaskStatus::InProgress)->and(Submission::query()->count())->toBe(0);
    });

    it('shows client-safe comments only, never internal ones', function () {
        TaskComment::factory()->create(['task_id' => $this->task->id, 'body' => 'Visible note']);
        TaskComment::factory()->internal()->create(['task_id' => $this->task->id, 'body' => 'Margin is thin, watch scope']);

        Livewire::actingAs($this->me)->test(TaskDetail::class, ['task' => $this->task])
            ->assertSee('Visible note')
            ->assertDontSee('Margin is thin')
            ->set('comment', 'Thanks, on it')
            ->call('addComment')
            ->assertSee('Thanks, on it');
    });

    it('does not open for another contributor', function () {
        Livewire::actingAs(contributorIn(OnboardingState::Active))->test(TaskDetail::class, ['task' => $this->task])->assertNotFound();
    });

    it('hides the submit form from an assignee whose seat is no longer active', function () {
        $this->task->forceFill(['status' => TaskStatus::InProgress])->save();
        $this->seat->forceFill(['status' => AssignmentStatus::Completed])->save();

        Livewire::actingAs($this->me)->test(TaskDetail::class, ['task' => $this->task])->assertDontSee('Submit work');
    });
});
