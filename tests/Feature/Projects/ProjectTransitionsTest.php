<?php

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
use App\Enums\AdminRole;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Project;
use App\Services\Projects\ProjectTransitionGuards;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->dm = adminUser(AdminRole::DeliveryManager);
});

function projectIn(ProjectStatus $status): Project
{
    return Project::factory()->status($status)->create();
}

/** A stand-in for the guards, to test the project state machine in isolation. */
function fakeGuards(bool $assignment, bool $milestones): void
{
    app()->instance(ProjectTransitionGuards::class, new class($assignment, $milestones) implements ProjectTransitionGuards
    {
        public function __construct(private bool $assignment, private bool $milestones) {}

        public function hasActiveAssignment(Project $project): bool
        {
            return $this->assignment;
        }

        public function allMilestonesAccepted(Project $project): bool
        {
            return $this->milestones;
        }
    });
}

it('moves a project along the happy path and logs each step', function () {
    fakeGuards(true, true);
    $project = projectIn(ProjectStatus::SowAccepted);

    foreach ([StartStaffing::class, ActivateProject::class, MoveProjectToQa::class, MarkProjectDelivered::class, AcceptProject::class, CloseProject::class] as $action) {
        app($action)($project, $this->dm);
    }

    expect($project->refresh()->status)->toBe(ProjectStatus::Closed)
        ->and($project->closed_at)->not->toBeNull();

    $events = Activity::query()->where('log_name', 'project')->pluck('event')->all();
    expect($events)->toEqualCanonicalizing(['staffing', 'active', 'in_qa', 'delivered', 'accepted', 'closed']);
});

it('requires an active assignment to go from staffing to active', function () {
    fakeGuards(false, true);
    $project = projectIn(ProjectStatus::Staffing);

    expect(fn () => app(ActivateProject::class)($project, $this->dm))->toThrow(ActionBlocked::class, 'active assignment');
    expect($project->refresh()->status)->toBe(ProjectStatus::Staffing);

    fakeGuards(true, true);
    expect(app(ActivateProject::class)($project, $this->dm)->status)->toBe(ProjectStatus::Active);
});

it('refuses to go active with the real guards when the project has no active assignment', function () {
    $project = projectIn(ProjectStatus::Staffing);

    expect(fn () => app(ActivateProject::class)($project, $this->dm))->toThrow(ActionBlocked::class);
});

it('requires all milestones accepted to go from delivered to accepted', function () {
    fakeGuards(true, false);
    $project = projectIn(ProjectStatus::Delivered);

    expect(fn () => app(AcceptProject::class)($project, $this->dm))->toThrow(ActionBlocked::class, 'milestones');

    fakeGuards(true, true);
    expect(app(AcceptProject::class)($project, $this->dm)->status)->toBe(ProjectStatus::Accepted);
});

it('puts a staffing or active project on hold', function (ProjectStatus $from) {
    expect(app(PutProjectOnHold::class)(projectIn($from), $this->dm, 'Client paused')->status)->toBe(ProjectStatus::OnHold);
})->with([ProjectStatus::Staffing, ProjectStatus::Active]);

it('cancels from any pre-closed state with a reason, and not once closed', function (ProjectStatus $from) {
    $project = projectIn($from);

    expect(app(CancelProject::class)($project, $this->dm, 'Client withdrew')->status)->toBe(ProjectStatus::Cancelled);

    $event = Activity::query()->where('log_name', 'project')->where('event', 'cancelled')->latest('id')->first();
    expect($event->properties['reason'])->toBe('Client withdrew');
})->with([
    ProjectStatus::Draft, ProjectStatus::SowSent, ProjectStatus::SowAccepted, ProjectStatus::Staffing, ProjectStatus::Active,
    ProjectStatus::InQa, ProjectStatus::Delivered, ProjectStatus::Accepted, ProjectStatus::OnHold,
]);

it('requires a reason to cancel', function () {
    expect(fn () => app(CancelProject::class)(projectIn(ProjectStatus::Draft), $this->dm, ' '))->toThrow(ActionBlocked::class);
});

it('rejects every illegal project transition', function (string $action, ProjectStatus $from, array $args) {
    fakeGuards(true, true);
    $project = projectIn($from);

    expect(fn () => app($action)($project, $this->dm, ...$args))->toThrow(IllegalTransition::class);
    expect($project->refresh()->status)->toBe($from);
})->with([
    'staffing from draft' => [StartStaffing::class, ProjectStatus::Draft, []],
    'staffing from active' => [StartStaffing::class, ProjectStatus::Active, []],
    'activate from sow_accepted' => [ActivateProject::class, ProjectStatus::SowAccepted, []],
    'qa from staffing' => [MoveProjectToQa::class, ProjectStatus::Staffing, []],
    'delivered from active' => [MarkProjectDelivered::class, ProjectStatus::Active, []],
    'accept from in_qa' => [AcceptProject::class, ProjectStatus::InQa, []],
    'close from delivered' => [CloseProject::class, ProjectStatus::Delivered, []],
    'hold from in_qa' => [PutProjectOnHold::class, ProjectStatus::InQa, ['why']],
    'hold from draft' => [PutProjectOnHold::class, ProjectStatus::Draft, ['why']],
    'cancel a closed project' => [CancelProject::class, ProjectStatus::Closed, ['why']],
    'cancel a cancelled project' => [CancelProject::class, ProjectStatus::Cancelled, ['why']],
]);

it('resumes a held project to the state it was held from and clears the stored state (SCR-008)', function (ProjectStatus $from) {
    $project = projectIn($from);

    app(PutProjectOnHold::class)($project, $this->dm, 'Client paused');
    expect($project->refresh()->status)->toBe(ProjectStatus::OnHold)->and($project->status_before_hold)->toBe($from);

    app(ResumeProject::class)($project, $this->dm);
    expect($project->refresh()->status)->toBe($from)->and($project->status_before_hold)->toBeNull();

    $events = Activity::query()->where('log_name', 'project')->pluck('event')->all();
    expect($events)->toContain('on_hold')->toContain($from->value);
})->with([ProjectStatus::Staffing, ProjectStatus::Active]);

it('cannot resume a project that is not on hold', function () {
    expect(fn () => app(ResumeProject::class)(projectIn(ProjectStatus::Active), $this->dm))->toThrow(IllegalTransition::class);
});

it('refuses to resume an on-hold project with no recorded state', function () {
    $project = projectIn(ProjectStatus::OnHold);

    expect(fn () => app(ResumeProject::class)($project, $this->dm))->toThrow(ActionBlocked::class, 'no recorded state');
    expect($project->refresh()->status)->toBe(ProjectStatus::OnHold);
});

it('only lets admins with project write access resume', function () {
    $project = projectIn(ProjectStatus::OnHold);
    $project->update(['status_before_hold' => ProjectStatus::Active]);

    expect(fn () => app(ResumeProject::class)($project, clientUser($project->organization)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ResumeProject::class)($project, adminUser(AdminRole::FinanceOps)))->toThrow(AuthorizationException::class);
});

it('allows only resume and cancel out of on_hold', function () {
    expect(ProjectStatus::OnHold->allowedTransitions())->toBe([ProjectStatus::Staffing, ProjectStatus::Active, ProjectStatus::Cancelled]);
});

it('only lets admins with project write access transition projects', function () {
    $project = projectIn(ProjectStatus::SowAccepted);

    expect(fn () => app(StartStaffing::class)($project, clientUser($project->organization)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(StartStaffing::class)($project, contributorUser()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(StartStaffing::class)($project, adminUser(AdminRole::CommunityManager)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(StartStaffing::class)($project, adminUser(AdminRole::FinanceOps)))->toThrow(AuthorizationException::class);
});

it('covers every status in the transition map', function () {
    foreach (ProjectStatus::cases() as $status) {
        expect($status->allowedTransitions())->toBeArray();
    }

    expect(ProjectStatus::Closed->allowedTransitions())->toBe([])
        ->and(ProjectStatus::Cancelled->allowedTransitions())->toBe([]);
});

it('returns a delivered project to QA when the client asks for changes (SCR-016)', function () {
    $project = projectIn(ProjectStatus::Delivered);
    $client = clientUser($project->organization);

    expect(app(ReopenProjectForChanges::class)($project, $client)->status)->toBe(ProjectStatus::InQa);

    $event = Activity::query()->where('log_name', 'project')->where('event', 'in_qa')->sole();
    expect($event->properties['from'])->toBe('delivered')
        ->and($event->properties['reason'])->toBe('client_requested_changes')
        ->and($event->causer_id)->toBe($client->id);
});

it('only reopens a delivered project', function (ProjectStatus $from) {
    $project = projectIn($from);

    expect(fn () => app(ReopenProjectForChanges::class)($project, null))->toThrow(IllegalTransition::class);
    expect($project->refresh()->status)->toBe($from);
})->with([ProjectStatus::Draft, ProjectStatus::Staffing, ProjectStatus::Active, ProjectStatus::InQa, ProjectStatus::Accepted, ProjectStatus::Closed, ProjectStatus::OnHold, ProjectStatus::Cancelled]);

it('lets an admin mark a reopened project delivered again', function () {
    fakeGuards(true, true);
    $project = projectIn(ProjectStatus::Delivered);

    app(ReopenProjectForChanges::class)($project, clientUser($project->organization));

    expect(app(MarkProjectDelivered::class)($project, $this->dm)->status)->toBe(ProjectStatus::Delivered);
});
