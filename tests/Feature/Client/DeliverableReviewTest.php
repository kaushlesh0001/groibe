<?php

use App\Actions\Milestones\AcceptMilestone;
use App\Actions\Milestones\RequestMilestoneChanges;
use App\Enums\AdminRole;
use App\Enums\AssignmentStatus;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Livewire\Client\Deliverables\DeliverableIndex;
use App\Models\Assignment;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Submission;
use App\Models\Task;
use App\Notifications\MilestoneResponded;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');

    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->org = Organization::factory()->verified()->create();
    $this->owner = clientUser($this->org, OrgRole::Owner);
    $this->manager = clientUser($this->org, OrgRole::Manager);
    $this->viewer = clientUser($this->org, OrgRole::Viewer);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['organization_id' => $this->org->id, 'delivery_manager_id' => $this->dm->id]);
    $this->contributor = contributorIn(OnboardingState::Active, [], ['name' => 'Priya Contributor']);
    $this->seat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->contributor->id, 'agreed_amount' => 876543]);
    $this->milestone = sharedMilestone($this->project, $this->seat, ['title' => 'Discovery phase']);
});

/** A shared milestone with one approved task and a deliverable holding one approved, internally noted submission. */
function sharedMilestone(Project $project, Assignment $seat, array $attributes = []): Milestone
{
    $milestone = Milestone::factory()->status(MilestoneStatus::Shared)->create(['project_id' => $project->id, ...$attributes]);
    $task = Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $milestone->id, 'assignment_id' => $seat->id]);
    $submission = Submission::factory()->status(SubmissionStatus::QaApproved)->create([
        'task_id' => $task->id,
        'assignment_id' => $seat->id,
        'note' => 'Contributor private note to QA',
        'qa_note' => 'Internal reviewer remark about margins',
    ]);
    $submission->addMedia(UploadedFile::fake()->create('market-report.pdf', 20, 'application/pdf'))->toMediaCollection(Submission::FILES);
    $deliverable = Deliverable::factory()->shared()->create(['milestone_id' => $milestone->id, 'title' => 'Market report', 'summary' => 'The full report']);
    $deliverable->submissions()->attach($submission);

    return $milestone;
}

describe('accepting', function () {
    it('records the acceptance and tells the delivery side', function () {
        Notification::fake();

        $milestone = app(AcceptMilestone::class)($this->milestone, $this->owner);

        expect($milestone->status)->toBe(MilestoneStatus::ClientAccepted)
            ->and($milestone->client_accepted_by)->toBe($this->owner->id)
            ->and($milestone->client_accepted_at)->not->toBeNull()
            ->and(Activity::query()->where('log_name', 'milestone')->where('event', 'client_accepted')->count())->toBe(1)
            ->and($this->project->refresh()->status)->toBe(ProjectStatus::Active);

        Notification::assertSentTo($this->dm, MilestoneResponded::class, fn ($n) => $n->accepted === true);
    });

    it('accepts the project when the last milestone of a delivered project is accepted', function () {
        $other = sharedMilestone($this->project, $this->seat, ['title' => 'Build phase']);
        $this->project->forceFill(['status' => ProjectStatus::Delivered])->save();

        app(AcceptMilestone::class)($this->milestone, $this->owner);
        expect($this->project->refresh()->status)->toBe(ProjectStatus::Delivered);

        app(AcceptMilestone::class)($other, $this->manager);

        expect($this->project->refresh()->status)->toBe(ProjectStatus::Accepted)
            ->and($this->seat->refresh()->status)->toBe(AssignmentStatus::Completed);

        $event = Activity::query()->where('log_name', 'project')->where('event', 'accepted')->sole();
        expect($event->properties['automatic'])->toBeTrue()->and($event->causer_id)->toBe($this->manager->id);
    });

    it('leaves a project that is not delivered yet alone', function (ProjectStatus $status) {
        $this->project->forceFill(['status' => $status])->save();

        app(AcceptMilestone::class)($this->milestone, $this->owner);

        expect($this->project->refresh()->status)->toBe($status);
    })->with([ProjectStatus::Active, ProjectStatus::InQa]);
});

describe('requesting changes', function () {
    it('keeps the note, tells the delivery side and returns a delivered project to QA (SCR-016)', function () {
        Notification::fake();
        $this->project->forceFill(['status' => ProjectStatus::Delivered])->save();

        $milestone = app(RequestMilestoneChanges::class)($this->milestone, $this->manager, '  Add the regional split  ');

        expect($milestone->status)->toBe(MilestoneStatus::ClientChangesRequested)
            ->and($milestone->client_note)->toBe('Add the regional split')
            ->and($milestone->client_accepted_at)->toBeNull()
            ->and($this->project->refresh()->status)->toBe(ProjectStatus::InQa);

        Notification::assertSentTo($this->dm, MilestoneResponded::class, fn ($n) => $n->accepted === false && $n->milestone->client_note === 'Add the regional split');
    });

    it('does not move a project that is not delivered', function () {
        app(RequestMilestoneChanges::class)($this->milestone, $this->owner, 'Please revise');

        expect($this->project->refresh()->status)->toBe(ProjectStatus::Active);
    });

    it('requires a note', function (string $note) {
        expect(fn () => app(RequestMilestoneChanges::class)($this->milestone, $this->owner, $note))->toThrow(ActionBlocked::class, 'what needs to change');
        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::Shared);
    })->with(['', '   ']);
});

describe('who may answer', function () {
    it('lets owners and managers answer, never viewers or outsiders', function (string $who, bool $allowed) {
        $actor = match ($who) {
            'owner' => $this->owner,
            'manager' => $this->manager,
            'viewer' => $this->viewer,
            'other_org_owner' => clientUser(),
            'contributor' => $this->contributor,
            'delivery_manager' => $this->dm,
        };

        if ($allowed) {
            expect(app(AcceptMilestone::class)($this->milestone, $actor)->status)->toBe(MilestoneStatus::ClientAccepted);

            return;
        }

        expect(fn () => app(AcceptMilestone::class)($this->milestone, $actor))->toThrow(AuthorizationException::class)
            ->and(fn () => app(RequestMilestoneChanges::class)($this->milestone, $actor, 'x'))->toThrow(AuthorizationException::class);
        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::Shared);
    })->with([
        ['owner', true],
        ['manager', true],
        ['viewer', false],
        ['other_org_owner', false],
        ['contributor', false],
        ['delivery_manager', false],
    ]);

    it('only answers a milestone that is waiting for review', function (MilestoneStatus $status) {
        $this->milestone->forceFill(['status' => $status])->save();

        expect(fn () => app(AcceptMilestone::class)($this->milestone, $this->owner))->toThrow(AuthorizationException::class)
            ->and(fn () => app(RequestMilestoneChanges::class)($this->milestone, $this->owner, 'x'))->toThrow(AuthorizationException::class);
    })->with([MilestoneStatus::Planned, MilestoneStatus::InProgress, MilestoneStatus::InQa, MilestoneStatus::ClientAccepted, MilestoneStatus::ClientChangesRequested]);

    it('answers only once', function () {
        app(AcceptMilestone::class)($this->milestone, $this->owner);

        expect(fn () => app(AcceptMilestone::class)($this->milestone, $this->owner))->toThrow(AuthorizationException::class)
            ->and(fn () => app(RequestMilestoneChanges::class)($this->milestone, $this->owner, 'too late'))->toThrow(AuthorizationException::class);
    });
});

describe('deliverables page', function () {
    it('keeps the page behind sign-in and the client role', function () {
        $url = route('client.deliverables');

        $this->get($url)->assertRedirect(route('login'));

        asUser($this->contributor);
        $this->get($url)->assertForbidden();

        asUser($this->dm);
        $this->get($url)->assertForbidden();

        asUser($this->viewer);
        $this->get($url)->assertOk()->assertSee('Discovery phase');
    });

    it('shows shared work with its files and nothing internal', function () {
        Livewire::actingAs($this->owner)->test(DeliverableIndex::class)
            ->assertSee('Discovery phase')
            ->assertSee('Market report')
            ->assertSee('The full report')
            ->assertSee('market-report.pdf')
            ->assertDontSee('Priya')
            ->assertDontSee('Contributor private note')
            ->assertDontSee('Internal reviewer remark')
            ->assertDontSee('876543');
    });

    it('shows only shared and later milestones of the own organization', function () {
        $planned = Milestone::factory()->create(['project_id' => $this->project->id, 'title' => 'Hidden planned']);
        $inQa = Milestone::factory()->status(MilestoneStatus::InQa)->create(['project_id' => $this->project->id, 'title' => 'Hidden in QA']);
        $accepted = Milestone::factory()->status(MilestoneStatus::ClientAccepted)->create(['project_id' => $this->project->id, 'title' => 'Visible accepted']);
        $foreignProject = Project::factory()->status(ProjectStatus::Active)->create();
        sharedMilestone($foreignProject, Assignment::factory()->active()->create(['project_id' => $foreignProject->id]), ['title' => 'Other org milestone']);
        $draftProject = Project::factory()->create(['organization_id' => $this->org->id]);
        sharedMilestone($draftProject, Assignment::factory()->active()->create(['project_id' => $draftProject->id]), ['title' => 'Draft project milestone']);

        Livewire::actingAs($this->owner)->test(DeliverableIndex::class)
            ->assertSee('Discovery phase')
            ->assertSee('Visible accepted')
            ->assertDontSee('Hidden planned')
            ->assertDontSee('Hidden in QA')
            ->assertDontSee('Other org milestone')
            ->assertDontSee('Draft project milestone');
    });

    it('lets an owner accept and request changes from the page', function () {
        $second = sharedMilestone($this->project, $this->seat, ['title' => 'Second phase']);

        $page = Livewire::actingAs($this->owner)->test(DeliverableIndex::class)
            ->assertSee('Accept')
            ->call('accept', $this->milestone->id)
            ->assertHasNoErrors();

        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::ClientAccepted);

        $page->call('startChanges', $second->id)
            ->set('changeNote', '')
            ->call('sendChanges')
            ->assertHasErrors('response');

        expect($second->refresh()->status)->toBe(MilestoneStatus::Shared);

        $page->set('changeNote', 'Please add the chart')->call('sendChanges')->assertHasNoErrors();

        expect($second->refresh()->status)->toBe(MilestoneStatus::ClientChangesRequested)
            ->and($second->client_note)->toBe('Please add the chart');
    });

    it('shows viewers the work without the buttons', function () {
        Livewire::actingAs($this->viewer)->test(DeliverableIndex::class)
            ->assertSee('Market report')
            ->assertSee('Only organization owners and managers')
            ->assertDontSee('Request changes');
    });

    it('will not let a viewer answer by calling the method directly', function () {
        Livewire::actingAs($this->viewer)->test(DeliverableIndex::class)->call('accept', $this->milestone->id)->assertHasErrors('response');

        expect($this->milestone->refresh()->status)->toBe(MilestoneStatus::Shared);
    });

    it('will not answer a milestone of another organization', function () {
        $foreignProject = Project::factory()->status(ProjectStatus::Active)->create();
        $foreign = sharedMilestone($foreignProject, Assignment::factory()->active()->create(['project_id' => $foreignProject->id]));

        Livewire::actingAs($this->owner)->test(DeliverableIndex::class)->call('accept', $foreign->id)->assertNotFound();

        expect($foreign->refresh()->status)->toBe(MilestoneStatus::Shared);
    });

    it('shows the client their own note while changes are being made', function () {
        app(RequestMilestoneChanges::class)($this->milestone, $this->owner, 'Needs the 2026 figures');

        Livewire::actingAs($this->viewer)->test(DeliverableIndex::class)
            ->assertSee('Needs the 2026 figures')
            ->assertSee('working on your changes')
            ->assertDontSee('Request changes');
    });
});
