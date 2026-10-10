<?php

use App\Enums\AdminRole;
use App\Enums\AssignmentStatus;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\SowStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\RelationManagers\AssignmentsRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\DeliverablesRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\MilestonesRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\TasksRelationManager;
use App\Filament\Admin\Resources\Submissions\Pages\ListSubmissions;
use App\Livewire\Client\Deliverables\DeliverableIndex;
use App\Livewire\Client\Projects\ProjectShow;
use App\Livewire\Contributor\Work\MyWork;
use App\Livewire\Contributor\Work\ProjectBoard;
use App\Livewire\Contributor\Work\TaskDetail;
use App\Models\Assignment;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Sow;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\Files\SignedFileUrl;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');

    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::SowAccepted)->create(['delivery_manager_id' => $this->dm->id, 'title' => 'Regional sales dashboard']);
    Sow::factory()->status(SowStatus::Accepted)->create(['project_id' => $this->project->id]);
    $this->owner = clientUser($this->project->organization, OrgRole::Owner);
    $this->me = contributorIn(OnboardingState::Active, ['headline' => 'Dashboard developer'], ['name' => 'Priya Sharma']);

    $this->editProject = fn () => Livewire::actingAs($this->dm)->test(EditProject::class, ['record' => $this->project->getRouteKey()]);
    $this->tab = fn (string $class) => Livewire::actingAs($this->dm)->test($class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class]);
    $this->hrefs = fn (string $html): array => collect(preg_match_all('/href="([^"]*\/files\/[^"]*)"/', $html, $m) ? $m[1] : [])->map(fn ($h) => html_entity_decode($h))->all();
});

/** Staffing to a project that is active with one contributor, one milestone and two assigned tasks. */
function staffAndPlan(): array
{
    $t = test();

    // Staffing needs an active assignment before the project may go active.
    ($t->editProject)()->callAction('startStaffing');
    expect($t->project->refresh()->status)->toBe(ProjectStatus::Staffing);

    ($t->tab)(AssignmentsRelationManager::class)->callAction(TestAction::make('invite')->table(), [
        'user_id' => $t->me->id, 'role_title' => 'Dashboard developer', 'rate_type' => 'fixed', 'agreed_amount' => 876543,
    ]);
    $seat = Assignment::query()->sole();
    expect($seat->status)->toBe(AssignmentStatus::Invited);

    ($t->editProject)()->callAction('activate');
    expect($t->project->refresh()->status)->toBe(ProjectStatus::Staffing); // an invite is not a seat yet

    Livewire::actingAs($t->me)->test(MyWork::class)->assertSee('Regional sales dashboard')->call('accept', $seat->id);
    expect($seat->refresh()->status)->toBe(AssignmentStatus::Active);

    ($t->editProject)()->callAction('activate');
    expect($t->project->refresh()->status)->toBe(ProjectStatus::Active);

    ($t->tab)(MilestonesRelationManager::class)->callAction(TestAction::make('addMilestone')->table(), ['title' => 'Build and ship']);
    $milestone = Milestone::query()->sole();

    foreach (['Data model', 'Dashboard pages'] as $title) {
        ($t->tab)(TasksRelationManager::class)->callAction(TestAction::make('addTask')->table(), [
            'milestone_id' => $milestone->id, 'title' => $title, 'estimate_hours' => 6, 'assignment_id' => $seat->id,
        ]);
    }

    return [$seat, $milestone, Task::query()->orderBy('id')->get()];
}

/** The contributor starts and submits a task through the real page. */
function doTask(Task $task, string $file = 'work.pdf'): void
{
    $page = Livewire::actingAs(test()->me)->test(TaskDetail::class, ['task' => $task])->call('start');
    $page->set('note', 'Ready for review')->set('uploads', [UploadedFile::fake()->create($file, 40, 'application/pdf')])->call('submit')->assertHasNoErrors();
}

it('runs a project from SOW accepted to closed through the real pages', function () {
    [$seat, $milestone, $tasks] = staffAndPlan();

    // An internal admin comment exists from the start and must never leave the building.
    TaskComment::factory()->internal()->create(['task_id' => $tasks[0]->id, 'user_id' => $this->dm->id, 'body' => 'Internal: margin on this one is thin']);

    // The contributor sees only their work, and starts it.
    Livewire::actingAs($this->me)->test(ProjectBoard::class, ['project' => $this->project])->assertSee('Data model')->assertSee('Dashboard pages');
    doTask($tasks[0], 'data-model.pdf');
    expect($milestone->refresh()->status)->toBe(MilestoneStatus::InProgress);
    doTask($tasks[1], 'pages.pdf');

    // Every task submitted: the milestone is in QA and the delivery manager is told.
    expect($milestone->refresh()->status)->toBe(MilestoneStatus::InQa)
        ->and($this->dm->notifications()->where('data->title', 'like', 'Milestone in QA%')->count())->toBe(1)
        ->and($this->dm->notifications()->where('data->title', 'like', 'Work submitted for QA%')->count())->toBe(2);

    // QA: approve both, and open a file from the review modal.
    $queue = Livewire::actingAs($this->dm)->test(ListSubmissions::class);
    foreach (Submission::query()->orderBy('id')->get() as $submission) {
        $queue->callAction(TestAction::make('approve')->table($submission), ['note' => null])->assertHasNoActionErrors();
    }
    expect(Task::query()->where('status', TaskStatus::Approved->value)->count())->toBe(2)
        ->and($milestone->refresh()->status)->toBe(MilestoneStatus::InQa)
        ->and($this->me->notifications()->where('data->title', 'like', 'Your work was approved%')->count())->toBe(2);

    $firstFile = Submission::query()->orderBy('id')->first()->getFirstMedia(Submission::FILES);
    $this->actingAs($this->dm)->get(app(SignedFileUrl::class)->for($firstFile))->assertOk();

    // Deliverable, then share.
    ($this->tab)(DeliverablesRelationManager::class)->callAction(TestAction::make('addDeliverable')->table(), [
        'milestone_id' => $milestone->id, 'title' => 'Dashboard and data model', 'summary' => 'Everything for launch',
        'submission_ids' => Submission::query()->pluck('id')->all(),
    ]);
    ($this->tab)(MilestonesRelationManager::class)->callAction(TestAction::make('shareMilestone')->table($milestone));
    expect($milestone->refresh()->status)->toBe(MilestoneStatus::Shared)
        ->and($this->owner->notifications()->where('data->title', 'like', 'Ready for your review%')->count())->toBe(1);

    // The client sees finished work and the team, never the contributor's surname, rate or internal notes.
    $page = Livewire::actingAs($this->owner)->test(DeliverableIndex::class)
        ->assertSee('Dashboard and data model')->assertSee('data-model.pdf')
        ->assertDontSee('Priya')->assertDontSee('Internal: margin')->assertDontSee('876543');
    $links = ($this->hrefs)($page->html());
    expect($links)->toHaveCount(2);
    $this->actingAs($this->owner)->get($links[0])->assertOk();

    Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $this->project->fresh()])
        ->assertSee('Priya')->assertDontSee('Sharma')->assertSee('Dashboard developer')
        ->assertSee('0 of 1 milestone accepted');

    // Delivery: admin moves the project to QA, then marks it delivered.
    ($this->editProject)()->callAction('moveToQa');
    expect($this->project->refresh()->status)->toBe(ProjectStatus::InQa);
    ($this->editProject)()->callAction('markDelivered');
    expect($this->project->refresh()->status)->toBe(ProjectStatus::Delivered)
        ->and($this->owner->notifications()->where('data->title', 'like', 'Your project has been delivered%')->count())->toBe(1);

    // The client accepts the only milestone, and the project accepts itself.
    Livewire::actingAs($this->owner)->test(DeliverableIndex::class)->call('accept', $milestone->id)->assertHasNoErrors();

    expect($milestone->refresh()->status)->toBe(MilestoneStatus::ClientAccepted)
        ->and($this->project->refresh()->status)->toBe(ProjectStatus::Accepted)
        ->and($seat->refresh()->status)->toBe(AssignmentStatus::Completed)
        ->and($this->me->notifications()->where('data->title', 'like', 'Project accepted%')->count())->toBe(1)
        ->and($this->dm->notifications()->where('data->title', 'like', 'Project accepted%')->count())->toBe(1);

    // The contributor can still open the board and the files after the project ends.
    Livewire::actingAs($this->me)->test(ProjectBoard::class, ['project' => $this->project])->assertOk();

    ($this->editProject)()->callAction('close');
    expect($this->project->refresh()->status)->toBe(ProjectStatus::Closed);

    // The whole progression is in the audit trail, in order.
    expect(Activity::query()->where('log_name', 'project')->where('subject_id', $this->project->id)->orderBy('id')->pluck('event')->all())
        ->toBe(['staffing', 'active', 'in_qa', 'delivered', 'accepted', 'closed']);
    expect(Activity::query()->where('log_name', 'milestone')->orderBy('id')->pluck('event')->all())
        ->toBe(['in_progress', 'in_qa', 'shared', 'client_accepted']);

    // Nothing internal reached anyone outside the delivery team, in any stored notification.
    $outbound = $this->owner->notifications->merge($this->me->notifications)->map(fn ($n) => json_encode($n->data))->implode(' ');
    expect($outbound)->not->toContain('Internal: margin')->not->toContain('876543');
});

it('handles a client change request after delivery and still reaches accepted', function () {
    [$seat, $milestone, $tasks] = staffAndPlan();

    foreach ($tasks as $task) {
        doTask($task);
    }
    $queue = Livewire::actingAs($this->dm)->test(ListSubmissions::class);
    foreach (Submission::query()->orderBy('id')->get() as $submission) {
        $queue->callAction(TestAction::make('approve')->table($submission), ['note' => null]);
    }
    ($this->tab)(DeliverablesRelationManager::class)->callAction(TestAction::make('addDeliverable')->table(), [
        'milestone_id' => $milestone->id, 'title' => 'Dashboard', 'submission_ids' => Submission::query()->pluck('id')->all(),
    ]);
    ($this->tab)(MilestonesRelationManager::class)->callAction(TestAction::make('shareMilestone')->table($milestone));
    ($this->editProject)()->callAction('moveToQa');
    ($this->editProject)()->callAction('markDelivered');
    expect($this->project->refresh()->status)->toBe(ProjectStatus::Delivered);

    // The client asks for a change. The project goes back to QA by itself.
    Livewire::actingAs($this->owner)->test(DeliverableIndex::class)
        ->call('startChanges', $milestone->id)->set('changeNote', 'Add a regional chart')->call('sendChanges')->assertHasNoErrors();

    expect($milestone->refresh()->status)->toBe(MilestoneStatus::ClientChangesRequested)
        ->and($this->project->refresh()->status)->toBe(ProjectStatus::InQa)
        ->and($this->dm->notifications()->where('data->title', 'like', '%asked for changes%')->count())->toBe(1);

    // The team reopens the milestone, adds follow-up work and does it. Approved work is untouched.
    ($this->tab)(MilestonesRelationManager::class)->callAction(TestAction::make('reopenMilestone')->table($milestone->refresh()));
    expect($milestone->refresh()->status)->toBe(MilestoneStatus::InQa); // every task was approved, so straight back to QA

    ($this->tab)(TasksRelationManager::class)->callAction(TestAction::make('addTask')->table(), [
        'milestone_id' => $milestone->id, 'title' => 'Regional chart', 'assignment_id' => $seat->id,
    ]);
    expect($milestone->refresh()->status)->toBe(MilestoneStatus::InProgress);

    $follow = Task::query()->where('title', 'Regional chart')->sole();
    doTask($follow, 'chart.pdf');
    $latest = Submission::query()->latest('id')->first();
    Livewire::actingAs($this->dm)->test(ListSubmissions::class)->callAction(TestAction::make('approve')->table($latest), ['note' => null]);
    expect($milestone->refresh()->status)->toBe(MilestoneStatus::InQa);

    $deliverable = Deliverable::query()->sole();
    ($this->tab)(DeliverablesRelationManager::class)->callAction(TestAction::make('editDeliverable')->table($deliverable), [
        'title' => 'Dashboard with regional chart', 'summary' => null, 'submission_ids' => Submission::query()->pluck('id')->all(),
    ]);
    ($this->tab)(MilestonesRelationManager::class)->callAction(TestAction::make('shareMilestone')->table($milestone));

    // A fresh round: the old note is gone for the client, and the project is delivered again.
    expect($milestone->refresh()->status)->toBe(MilestoneStatus::Shared)->and($milestone->client_note)->toBeNull();
    ($this->editProject)()->callAction('markDelivered');
    expect($this->project->refresh()->status)->toBe(ProjectStatus::Delivered);

    Livewire::actingAs($this->owner)->test(DeliverableIndex::class)->assertSee('Dashboard with regional chart')->assertSee('chart.pdf')->call('accept', $milestone->id);

    expect($this->project->refresh()->status)->toBe(ProjectStatus::Accepted)
        ->and($seat->refresh()->status)->toBe(AssignmentStatus::Completed)
        ->and(Submission::query()->where('status', SubmissionStatus::QaApproved->value)->count())->toBe(3);

    expect(Activity::query()->where('log_name', 'project')->where('subject_id', $this->project->id)->orderBy('id')->pluck('event')->all())
        ->toBe(['staffing', 'active', 'in_qa', 'delivered', 'in_qa', 'delivered', 'accepted']);
});
