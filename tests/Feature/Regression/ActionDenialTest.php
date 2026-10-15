<?php

use App\Actions\Briefs\ConvertBriefToProject;
use App\Actions\Briefs\DeclineBrief;
use App\Actions\Briefs\MarkBriefScoped;
use App\Actions\Briefs\RequestBriefInfo;
use App\Actions\Projects\AcceptProject;
use App\Actions\Projects\ActivateProject;
use App\Actions\Projects\CancelProject;
use App\Actions\Projects\MarkProjectDelivered;
use App\Actions\Projects\MoveProjectToQa;
use App\Actions\Projects\PutProjectOnHold;
use App\Enums\AdminRole;
use App\Enums\BriefStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Models\Assignment;
use App\Models\Brief;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

/*
 * Pass 9 item 8 (review finding): the project and brief transition Actions authorize through a shared trait, and the existing
 * matrix test counts an authorization failure, a state failure and a guard failure all as "refused". These tests need the
 * authorization failure itself, from the correct source state, for everyone who must not make the move (spec 5, 6.1, 6.2).
 */

beforeEach(function () {
    Notification::fake();
    publishAgreements();

    $this->org = Organization::factory()->verified()->create();
    $this->owner = clientUser($this->org, OrgRole::Owner);
    $this->manager = clientUser($this->org, OrgRole::Manager);
    $this->contributor = contributorIn(OnboardingState::Active);
    $this->community = adminUser(AdminRole::CommunityManager);
    $this->finance = adminUser(AdminRole::FinanceOps);
    $this->stranger = clientUser(Organization::factory()->verified()->create(), OrgRole::Owner);
    $this->dm = adminUser(AdminRole::DeliveryManager);

    /** Everyone who must be refused a staff move on this organization's records. */
    $this->refused = fn (): array => [
        'client owner' => $this->owner,
        'client manager' => $this->manager,
        'another organization' => $this->stranger,
        'contributor' => $this->contributor,
        'community manager' => $this->community,
        'finance' => $this->finance,
    ];

    $this->projectIn = function (ProjectStatus $status): Project {
        $project = Project::factory()->status($status)->create(['organization_id' => $this->org->id, 'delivery_manager_id' => $this->dm->id]);
        Assignment::factory()->active()->create(['project_id' => $project->id, 'user_id' => $this->contributor->id]);

        return $project;
    };

    $this->briefIn = fn (BriefStatus $status): Brief => Brief::factory()->status($status)->create(['organization_id' => $this->org->id]);
});

it('refuses every project transition to anyone but the delivery side, from the correct source state', function (string $action, ProjectStatus $from, array $arguments) {
    foreach (($this->refused)() as $who => $actor) {
        $project = ($this->projectIn)($from);

        expect(fn () => app($action)($project, $actor, ...$arguments))->toThrow(AuthorizationException::class, '', "{$who} on {$action}");
        expect($project->refresh()->status)->toBe($from, "{$who} changed the project with {$action}");
    }
})->with([
    'activate' => [ActivateProject::class, ProjectStatus::Staffing, []],
    'move to QA' => [MoveProjectToQa::class, ProjectStatus::Active, []],
    'mark delivered' => [MarkProjectDelivered::class, ProjectStatus::InQa, []],
    'accept' => [AcceptProject::class, ProjectStatus::Delivered, []],
    'put on hold' => [PutProjectOnHold::class, ProjectStatus::Active, ['Waiting for the client']],
    'cancel' => [CancelProject::class, ProjectStatus::Active, ['Client withdrew']],
]);

it('lets the delivery manager hold and cancel the same projects, so the refusals above are about the person', function () {
    $held = ($this->projectIn)(ProjectStatus::Active);
    $cancelled = ($this->projectIn)(ProjectStatus::Active);

    expect(app(PutProjectOnHold::class)($held, $this->dm, 'Waiting')->status)->toBe(ProjectStatus::OnHold)
        ->and(app(CancelProject::class)($cancelled, $this->dm, 'Client withdrew')->status)->toBe(ProjectStatus::Cancelled);
});

it('refuses every brief decision to the client and to staff without brief rights, from the correct source state', function (string $action, BriefStatus $from, array $arguments) {
    foreach (($this->refused)() as $who => $actor) {
        $brief = ($this->briefIn)($from);

        expect(fn () => app($action)($brief, $actor, ...$arguments))->toThrow(AuthorizationException::class, '', "{$who} on {$action}");
        expect($brief->refresh()->status)->toBe($from, "{$who} changed the brief with {$action}");
    }
})->with([
    'ask the client for information' => [RequestBriefInfo::class, BriefStatus::InReview, ['What is the budget?']],
    'mark scoped' => [MarkBriefScoped::class, BriefStatus::InReview, []],
    'decline' => [DeclineBrief::class, BriefStatus::InReview, ['Out of scope']],
]);

it('refuses converting a scoped brief to a project to everyone but the delivery side', function () {
    foreach (($this->refused)() as $who => $actor) {
        $brief = ($this->briefIn)(BriefStatus::Scoped);

        expect(fn () => app(ConvertBriefToProject::class)($brief, $actor))->toThrow(AuthorizationException::class, '', $who);
        expect($brief->refresh()->status)->toBe(BriefStatus::Scoped)->and(Project::query()->where('brief_id', $brief->id)->exists())->toBeFalse();
    }
});

it('lets the delivery manager make the brief decisions, so the refusals above are about the person', function () {
    expect(app(MarkBriefScoped::class)(($this->briefIn)(BriefStatus::InReview), $this->dm)->status)->toBe(BriefStatus::Scoped)
        ->and(app(DeclineBrief::class)(($this->briefIn)(BriefStatus::InReview), $this->dm, 'Out of scope')->status)->toBe(BriefStatus::Declined)
        ->and(app(RequestBriefInfo::class)(($this->briefIn)(BriefStatus::InReview), $this->dm, 'What is the budget?')->status)->toBe(BriefStatus::NeedsInfo);
});

it('refuses a suspended account every move too', function () {
    $suspended = User::factory()->admin(AdminRole::DeliveryManager)->suspended()->create();
    $project = ($this->projectIn)(ProjectStatus::Active);

    expect(fn () => app(PutProjectOnHold::class)($project, $suspended, 'x'))->toThrow(AuthorizationException::class);
    expect($project->refresh()->status)->toBe(ProjectStatus::Active);
});
