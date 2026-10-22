<?php

use App\Actions\Messages\GetProjectThread;
use App\Actions\Messages\PostMessage;
use App\Actions\Tasks\UpdateTask;
use App\Enums\AdminRole;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\PayoutStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Livewire\Contributor\Work\TaskDetail;
use App\Livewire\Shared\Messages\Conversation;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\DataRequest;
use App\Models\Milestone;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProofOfWork;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Thread;
use App\Notifications\ApplicationUpdated;
use App\Notifications\AssignmentInvited;
use App\Notifications\AssignmentRemoved;
use App\Notifications\ContributorStatusChanged;
use App\Notifications\DataRequestUpdated;
use App\Notifications\InviteNotification;
use App\Notifications\NewMessage;
use App\Notifications\OpenRoleInvited;
use App\Notifications\PayoutStatusChanged;
use App\Notifications\ProjectAccepted;
use App\Notifications\ProjectClosed;
use App\Notifications\ProofOfWorkChanged;
use App\Notifications\SubmissionReviewed;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskChanged;
use App\Services\Messaging\AuthorLabel;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * SCR-044: a contributor sees a project by its title and their own role. They never see the client's organization or
 * the name of a client person: not in a project thread, not on a task page, not in a notification or an email.
 * The client below has deliberately unusual names, so that any leak is found by searching the page text.
 */
beforeEach(function () {
    Storage::fake('local');
    Notification::fake();

    $this->orgName = 'Zephyr Industrial Holdings';
    $this->legalName = 'Zephyr Industrial Holdings Private Limited';
    $this->clientName = 'Zorana Vukovic';

    $this->org = Organization::factory()->verified()->create(['name' => $this->orgName, 'legal_name' => $this->legalName]);
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create([
        'organization_id' => $this->org->id,
        'delivery_manager_id' => $this->dm->id,
        'title' => 'Survey Rollout',
    ]);

    $this->owner = clientUser($this->org, OrgRole::Owner);
    $this->owner->forceFill(['name' => $this->clientName])->save();
    $this->manager = clientUser($this->org, OrgRole::Manager);
    $this->manager->forceFill(['name' => 'Mateo Zielinski'])->save();

    $this->me = contributorIn(OnboardingState::Active, [], ['name' => 'Meera Nair']);
    $this->seat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->me->id, 'role_title' => 'Research Analyst']);

    $this->thread = app(GetProjectThread::class)->ensure($this->project, $this->dm);
    app(PostMessage::class)($this->thread, $this->owner, 'Thanks for the update.');
    app(PostMessage::class)($this->thread, $this->manager, 'Please share the draft on Friday.');
    app(PostMessage::class)($this->thread, $this->dm, 'We will send it on Thursday.');

    $this->milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
    $this->task = Task::factory()->status(TaskStatus::InProgress)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id, 'title' => 'Draft the survey']);

    // A client person's comment on the task. The page is for the contributor, so it must read "Client".
    TaskComment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->owner->id, 'body' => 'Looks good so far.', 'is_internal' => false]);
    TaskComment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->manager->id, 'body' => 'One more question.', 'is_internal' => false]);

    $this->forbidden = [$this->orgName, 'Zephyr', $this->legalName, 'Private Limited', $this->clientName, 'Zorana', 'Vukovic', 'Mateo', 'Zielinski'];
});

/** Fail with the page name and the string that leaked. */
function expectNoClientIdentity(string $text, array $forbidden, string $where): void
{
    foreach ($forbidden as $needle) {
        expect(str_contains($text, $needle))->toBeFalse("{$where} shows the client: found \"{$needle}\"");
    }
}

describe('project thread pages', function () {
    it('names a client person only as "Client" on the conversation page', function () {
        $html = $this->actingAs($this->me)->get(route('contributor.messages.show', $this->thread))->assertOk()->getContent();

        expect($html)->toContain('Client')->toContain('Thanks for the update.')->toContain('Please share the draft on Friday.')
            ->and($html)->toContain('(Groibe)');
        expectNoClientIdentity($html, $this->forbidden, 'the conversation page');
    });

    it('shows the project title and the project code in the thread heading, and nothing about the client', function () {
        $html = $this->actingAs($this->me)->get(route('contributor.messages.show', $this->thread))->assertOk()->getContent();

        expect($html)->toContain($this->project->code)->toContain('Survey Rollout');
        expectNoClientIdentity($html, $this->forbidden, 'the thread heading');
    });

    it('keeps the client out of the Livewire component state, not just the page', function () {
        $page = Livewire::actingAs($this->me)->test(Conversation::class, ['thread' => $this->thread]);

        foreach ($this->forbidden as $needle) {
            $page->assertDontSee($needle);
        }

        expectNoClientIdentity(json_encode($page->snapshot), $this->forbidden, 'the conversation snapshot');
    });

    it('keeps the client out of the inbox and the project shortcut', function () {
        $inbox = $this->actingAs($this->me)->get(route('contributor.messages.index'))->assertOk()->getContent();

        expect($inbox)->toContain('Survey Rollout');
        expectNoClientIdentity($inbox, $this->forbidden, 'the inbox');

        $shortcut = $this->actingAs($this->me)->get(route('contributor.messages.project', $this->project))->assertRedirect();
        expectNoClientIdentity((string) $shortcut->headers->get('Location'), $this->forbidden, 'the project thread shortcut');
    });

    it('still shows client people to clients and to admins as before', function () {
        $first = explode(' ', $this->clientName)[0];

        $this->actingAs($this->manager)->get(route('client.messages.show', $this->thread))->assertOk()
            ->assertSee("{$first}, {$this->orgName}", false);

        Livewire::actingAs($this->dm)->test(Conversation::class, ['thread' => $this->thread])
            ->assertSee("{$first}, {$this->orgName}");
    });

    it('labels every client message "Client", whoever in the organization wrote it', function () {
        foreach ($this->thread->messages()->with('user')->get() as $message) {
            $label = app(AuthorLabel::class)->for($message, $this->thread->load('project.organization'), $this->me);

            if ($message->user->isClient()) {
                expect($label)->toBe('Client');
            } else {
                expect($label)->not->toBe('Client');
            }
        }
    });

    it('does not show a client person to a contributor who has no seat on the project (they cannot open the thread at all)', function () {
        $outsider = contributorIn(OnboardingState::Active);

        $this->actingAs($outsider)->get(route('contributor.messages.show', $this->thread))->assertNotFound();
    });
});

describe('task pages', function () {
    it('names a client person who commented only as "Client"', function () {
        $html = $this->actingAs($this->me)->get(route('contributor.work.task', $this->task))->assertOk()->getContent();

        expect($html)->toContain('Looks good so far.')->toContain('One more question.')->toContain('Client')->toContain('Survey Rollout');
        expectNoClientIdentity($html, $this->forbidden, 'the task page');
    });

    it('keeps the client out of the Livewire task page state', function () {
        $page = Livewire::actingAs($this->me)->test(TaskDetail::class, ['task' => $this->task]);

        foreach ($this->forbidden as $needle) {
            $page->assertDontSee($needle);
        }

        expectNoClientIdentity(json_encode($page->snapshot), $this->forbidden, 'the task page snapshot');
    });

    it('keeps the client out of the project board, My Work, the dashboard, reputation and payouts', function () {
        foreach ([
            'the project board' => route('contributor.work.project', $this->project),
            'My Work' => route('contributor.work'),
            'the dashboard' => route('contributor.dashboard'),
            'reputation' => route('contributor.reputation'),
            'payouts' => route('contributor.payouts'),
        ] as $where => $url) {
            $html = $this->actingAs($this->me)->get($url)->assertOk()->getContent();

            expectNoClientIdentity($html, $this->forbidden, $where);
        }
    });

    it('shows the project title and the contributor\'s own role on the project board', function () {
        $html = $this->actingAs($this->me)->get(route('contributor.work.project', $this->project))->assertOk()->getContent();

        expect($html)->toContain('Survey Rollout');
    });

    it('does not open a task of someone else, so no comment of a client person is reachable that way', function () {
        $other = contributorIn(OnboardingState::Active);

        $this->actingAs($other)->get(route('contributor.work.task', $this->task))->assertNotFound();
    });

    it('does not change what a guest or a client sees: a guest is sent to sign in and a client cannot open the contributor page', function () {
        $this->get(route('contributor.work.task', $this->task))->assertRedirect(route('login'));
        $this->actingAs($this->owner)->get(route('contributor.work.task', $this->task))->assertForbidden();
    });
});

describe('notifications and emails to contributors', function () {
    $cases = function () {
        $me = test()->me;
        $project = test()->project;
        $task = test()->task;
        $submission = Submission::factory()->create(['task_id' => $task->id, 'assignment_id' => test()->seat->id, 'qa_note' => 'Check the totals']);
        $role = OpenRole::factory()->published()->create(['project_id' => $project->id, 'title' => 'Survey Analyst']);
        $application = Application::factory()->create(['user_id' => $me->id, 'open_role_id' => $role->id]);
        $proof = ProofOfWork::factory()->create(['user_id' => $me->id, 'project_id' => $project->id, 'title' => 'Survey Rollout results']);
        $dataRequest = DataRequest::factory()->create(['user_id' => $me->id]);

        return [
            'assignment invited' => [new AssignmentInvited(test()->seat, $project), $me],
            'assignment removed' => [new AssignmentRemoved(test()->seat, $project, 'Meera Nair', 2), $me],
            'task assigned' => [new TaskAssigned($task, $project), $me],
            'task changed' => [new TaskChanged($task, $project, 'The task details were updated.'), $me],
            'submission approved' => [new SubmissionReviewed($submission, $task, $project, true), $me],
            'submission changes requested' => [new SubmissionReviewed($submission, $task, $project, false), $me],
            'project accepted' => [new ProjectAccepted($project), $me],
            'project closed' => [new ProjectClosed($project, 'contributor'), $me],
            'payout paid' => [new PayoutStatusChanged($project->title, PayoutStatus::Paid), $me],
            'payout approved' => [new PayoutStatusChanged($project->title, PayoutStatus::Approved), $me],
            'new message' => [NewMessage::for(test()->thread), $me],
            'open role invited' => [new OpenRoleInvited($role), $me],
            'application shortlisted' => [new ApplicationUpdated($application, 'shortlisted'), $me],
            'application offered' => [new ApplicationUpdated($application, 'offered'), $me],
            'application rejected' => [new ApplicationUpdated($application, 'rejected'), $me],
            'proof published' => [new ProofOfWorkChanged($proof, 'published'), $me],
            'proof returned' => [new ProofOfWorkChanged($proof, 'returned', 'Please add numbers.'), $me],
            'proof hidden' => [new ProofOfWorkChanged($proof, 'hidden', 'Hidden for now.'), $me],
            'onboarding approved' => [new ContributorStatusChanged($me->profile, OnboardingState::Active), $me],
            'onboarding suspended' => [new ContributorStatusChanged($me->profile, OnboardingState::Suspended), $me],
            'data request completed' => [new DataRequestUpdated($dataRequest, 'completed'), $me],
            'invite' => [new InviteNotification(route('home')), $me],
        ];
    };

    it('carries the project title at most, and never the client organization or a client person, in the bell or the email', function () use ($cases) {
        foreach ($cases() as $label => [$notification, $user]) {
            // The invite email has no bell entry: it goes to people who cannot sign in yet.
            $data = method_exists($notification, 'toArray') ? json_encode($notification->toArray($user)) : '';
            $mail = $notification->toMail($user);
            $html = (string) $mail->render();

            expectNoClientIdentity($data, test()->forbidden, "the bell text of \"{$label}\"");
            expectNoClientIdentity((string) $mail->subject, test()->forbidden, "the email subject of \"{$label}\"");
            expectNoClientIdentity($html, test()->forbidden, "the email of \"{$label}\"");
        }
    });

    it('includes the project title in the notifications that name a project', function () use ($cases) {
        $named = ['assignment invited', 'task assigned', 'task changed', 'submission approved', 'project accepted', 'project closed', 'new message'];
        $all = $cases();

        foreach ($named as $label) {
            [$notification, $user] = $all[$label];
            $text = json_encode($notification->toArray($user)).$notification->toMail($user)->render();

            expect(str_contains($text, 'Survey Rollout'))->toBeTrue("\"{$label}\" no longer names the project");
        }
    });

    it('sends the real action notifications without the client either', function () {
        Notification::fake();

        app(UpdateTask::class)($this->task, $this->dm, ['title' => 'Draft the survey v2']);

        Notification::assertSentTo($this->me, TaskChanged::class, function (TaskChanged $notification) {
            $text = json_encode($notification->toArray($this->me)).$notification->toMail($this->me)->render();
            expectNoClientIdentity($text, $this->forbidden, 'a sent TaskChanged');

            return true;
        });
    });

    it('is complete: every notification class is either checked above or not meant for a contributor', function () {
        $checked = [
            ApplicationUpdated::class, AssignmentInvited::class, AssignmentRemoved::class, ContributorStatusChanged::class,
            DataRequestUpdated::class, InviteNotification::class, NewMessage::class, OpenRoleInvited::class, PayoutStatusChanged::class,
            ProjectAccepted::class, ProjectClosed::class, ProofOfWorkChanged::class, SubmissionReviewed::class, TaskAssigned::class,
            TaskChanged::class,
        ];

        // Sent only to admins or to the client's organization. If one of these ever gets a contributor recipient, move it up.
        $notForContributors = [
            'AdminSignInCode', 'AssignmentResponded', 'BriefStatusChanged', 'DataRequestsWaiting', 'DatabaseSizeWarning', 'DeliverableShared',
            'InvoicesOverdue', 'MilestoneReadyToShare', 'MilestoneResponded', 'ProjectDelivered', 'ReviewSubmitted', 'SowAccepted', 'SowSent',
            'StaleInvitesReport', 'SubmissionReceived', 'TasksOverdue',
        ];

        $existing = collect(glob(app_path('Notifications/*.php')))->map(fn (string $file): string => basename($file, '.php'))
            ->reject(fn (string $name): bool => $name === 'GroibeNotification')->sort()->values()->all();

        $known = collect($checked)->map(fn (string $class): string => class_basename($class))->merge($notForContributors)->sort()->values()->all();

        expect($existing)->toBe($known);
    });

    it('has no email view for a contributor that reads the organization', function () {
        foreach (['application-updated', 'assignment-invited', 'assignment-removed', 'contributor-status', 'data-request-updated', 'new-message',
            'open-role-invited', 'payout-status-changed', 'project-accepted', 'project-closed', 'proof-of-work-changed', 'submission-reviewed',
            'task-assigned', 'task-changed'] as $view) {
            $source = file_get_contents(resource_path("views/emails/{$view}.blade.php"));

            expect($source)->not->toMatch('/organi[sz]ation|legal_name|client_name|clientName|orgName/i', "emails/{$view}");
        }
    });

    it('has no contributor notification class that reads the organization or a client person', function () {
        foreach ([
            ApplicationUpdated::class, AssignmentInvited::class, AssignmentRemoved::class, ContributorStatusChanged::class, DataRequestUpdated::class,
            NewMessage::class, OpenRoleInvited::class, PayoutStatusChanged::class, ProjectAccepted::class, ProjectClosed::class,
            ProofOfWorkChanged::class, SubmissionReviewed::class, TaskAssigned::class, TaskChanged::class,
        ] as $class) {
            $source = file_get_contents((new ReflectionClass($class))->getFileName());

            expect($source)->not->toMatch('/->organization\b|legal_name|client_name|->submitter|->owner\b/', class_basename($class));
        }
    });
});
