<?php

use App\Actions\Admin\StartImpersonation;
use App\Actions\Agreements\AcceptClientMsa;
use App\Actions\Contributors\AcceptMemberAgreement;
use App\Actions\Milestones\AcceptMilestone;
use App\Actions\Milestones\RequestMilestoneChanges;
use App\Actions\Privacy\OpenDataRequest;
use App\Actions\Projects\SetShowcaseConsent;
use App\Actions\Sows\AcceptSow;
use App\Enums\DataRequestType;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\ShowcaseConsent;
use App\Enums\SowStatus;
use App\Exceptions\ActionBlocked;
use App\Models\AgreementAcceptance;
use App\Models\DataRequest;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sow;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/*
 * Pass 9 item 8 (review finding): while a super admin impersonates someone, the audit log names the admin too, and the acts that
 * bind a person are refused. A SOW or Client MSA accepted "by the client" with the admin's address would be false evidence.
 */

/** Puts the current request into the state it is in while an admin is impersonating, as StartImpersonation leaves the session. */
function impersonating(User $admin): void
{
    request()->setLaravelSession(app('session.store'));
    session()->put(StartImpersonation::SESSION_KEY, $admin->id);
}

function stopImpersonating(): void
{
    session()->forget(StartImpersonation::SESSION_KEY);
}

const REFUSED_WHILE_IMPERSONATING = 'Only the person themselves can do this';

beforeEach(function () {
    publishAgreements();
    $this->super = adminUser();
});

it('refuses to accept a Statement of Work while impersonating, and accepts it from the client themselves', function () {
    $organization = Organization::factory()->verified()->create();
    $owner = acceptClientMsa($organization);
    $project = Project::factory()->status(ProjectStatus::SowSent)->create(['organization_id' => $organization->id]);
    $sow = Sow::factory()->status(SowStatus::Sent)->create(['project_id' => $project->id]);

    impersonating($this->super);

    expect(fn () => app(AcceptSow::class)($sow, $owner, true, '203.0.113.9', 'Admin browser'))->toThrow(ActionBlocked::class, REFUSED_WHILE_IMPERSONATING);
    expect($sow->refresh()->status)->toBe(SowStatus::Sent)->and($sow->accepted_ip)->toBeNull();

    stopImpersonating();

    expect(app(AcceptSow::class)($sow, $owner, true, '198.51.100.7', 'Client browser')->status)->toBe(SowStatus::Accepted);
});

it('refuses the Client MSA while impersonating', function () {
    $organization = Organization::factory()->verified()->create();
    $owner = clientUser($organization, OrgRole::Owner);

    impersonating($this->super);

    expect(fn () => app(AcceptClientMsa::class)($owner, $organization, '203.0.113.9', 'Admin browser'))->toThrow(ActionBlocked::class, REFUSED_WHILE_IMPERSONATING);
    expect(AgreementAcceptance::query()->count())->toBe(0);

    stopImpersonating();
    app(AcceptClientMsa::class)($owner, $organization, '198.51.100.7', 'Client browser');

    expect(AgreementAcceptance::query()->count())->toBe(1);
});

it('refuses the Member Agreement while impersonating', function () {
    $contributor = contributorIn(OnboardingState::ProfileComplete);

    impersonating($this->super);

    expect(fn () => app(AcceptMemberAgreement::class)($contributor, '203.0.113.9', 'Admin browser'))->toThrow(ActionBlocked::class, REFUSED_WHILE_IMPERSONATING);
    expect(AgreementAcceptance::query()->count())->toBe(0);

    stopImpersonating();
    app(AcceptMemberAgreement::class)($contributor, '198.51.100.7', 'Contributor browser');

    expect(AgreementAcceptance::query()->count())->toBe(1);
});

it('refuses showcase consent while impersonating', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Accepted);

    impersonating($this->super);

    expect(fn () => app(SetShowcaseConsent::class)($seat['project'], $seat['owner'], ShowcaseConsent::Named))->toThrow(ActionBlocked::class, REFUSED_WHILE_IMPERSONATING);
    expect($seat['project']->refresh()->showcase_consent)->toBe(ShowcaseConsent::None);

    stopImpersonating();
    app(SetShowcaseConsent::class)($seat['project'], $seat['owner'], ShowcaseConsent::Named);

    expect($seat['project']->refresh()->showcase_consent)->toBe(ShowcaseConsent::Named);
});

it('refuses a data request while impersonating', function () {
    $contributor = contributorIn(OnboardingState::Active);

    impersonating($this->super);

    expect(fn () => app(OpenDataRequest::class)($contributor, DataRequestType::Deletion))->toThrow(ActionBlocked::class, REFUSED_WHILE_IMPERSONATING);
    expect(DataRequest::query()->count())->toBe(0);

    stopImpersonating();
    app(OpenDataRequest::class)($contributor, DataRequestType::Deletion);

    expect(DataRequest::query()->count())->toBe(1);
});

it('refuses accepting a milestone or asking for changes while impersonating', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Delivered);
    $milestone = Milestone::factory()->status(MilestoneStatus::Shared)->create(['project_id' => $seat['project']->id]);

    impersonating($this->super);

    expect(fn () => app(AcceptMilestone::class)($milestone, $seat['owner']))->toThrow(ActionBlocked::class, REFUSED_WHILE_IMPERSONATING)
        ->and(fn () => app(RequestMilestoneChanges::class)($milestone, $seat['owner'], 'Please change the chart'))->toThrow(ActionBlocked::class, REFUSED_WHILE_IMPERSONATING);
    expect($milestone->refresh()->status)->toBe(MilestoneStatus::Shared);

    stopImpersonating();
    app(AcceptMilestone::class)($milestone, $seat['owner']);

    expect($milestone->refresh()->status)->toBe(MilestoneStatus::ClientAccepted);
});

it('does not refuse anything to an admin working as themselves', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Accepted);

    // No impersonation in the session: the same call by the person is allowed.
    expect(app(SetShowcaseConsent::class)($seat['project'], $seat['owner'], ShowcaseConsent::Anonymous)->showcase_consent)->toBe(ShowcaseConsent::Anonymous);
});

it('names the admin on every audit entry written while impersonating, and on none written otherwise', function () {
    activity('test')->log('before');

    impersonating($this->super);
    activity('test')->causedBy(clientUser())->log('during');
    stopImpersonating();

    activity('test')->log('after');

    $entries = Activity::query()->where('log_name', 'test')->orderBy('id')->get()->keyBy('description');

    expect($entries['before']->properties->has('impersonated_by'))->toBeFalse()
        ->and($entries['during']->properties['impersonated_by'])->toBe($this->super->id)
        ->and($entries['after']->properties->has('impersonated_by'))->toBeFalse();
});

it('leaves the start and stop entries of impersonation as the admin\'s own', function () {
    $target = clientUser();

    $this->actingAs($this->super);
    app(StartImpersonation::class)($this->super, $target);
    $this->post(route('impersonate.stop'))->assertRedirect('/admin');

    foreach (Activity::query()->where('log_name', 'impersonation')->get() as $entry) {
        expect($entry->causer_id)->toBe($this->super->id)->and($entry->properties->has('impersonated_by'))->toBeFalse();
    }
});

it('still lets the admin read the pages as the person', function () {
    $owner = clientUser();

    $this->actingAs($this->super);
    app(StartImpersonation::class)($this->super, $owner);

    $this->get('/app/client')->assertOk()->assertSee('Impersonating');
});
