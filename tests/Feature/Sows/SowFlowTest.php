<?php

use App\Actions\Sows\AcceptSow;
use App\Actions\Sows\CreateSow;
use App\Actions\Sows\SendSow;
use App\Actions\Sows\UpdateSow;
use App\Enums\AdminRole;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\SowStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sow;
use App\Notifications\SowAccepted;
use App\Notifications\SowSent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    publishAgreements();
    $this->org = Organization::factory()->verified()->create(['name' => 'Acme Foods', 'legal_name' => 'Acme Foods Pvt Ltd']);
    $this->owner = acceptClientMsa($this->org);
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->create([
        'organization_id' => $this->org->id,
        'title' => 'Sales dashboard',
        'scope_summary' => "Build a dashboard.\nWith <b>filters</b>.",
        'contract_value' => 250000,
        'start_date' => '2026-11-01',
        'due_date' => '2026-12-15',
        'delivery_manager_id' => $this->dm->id,
    ]);
});

function draftSow(array $data = []): Sow
{
    return app(CreateSow::class)(test()->project, test()->dm, $data);
}

it('renders the SOW template variables and stores the rendered HTML (spec 8)', function () {
    $sow = draftSow(['payment_terms' => '50% upfront']);

    expect($sow->version)->toBe(1)
        ->and($sow->status)->toBe(SowStatus::Draft)
        ->and($sow->body_html)->toContain('Sales dashboard')
        ->and($sow->body_html)->toContain('Acme Foods Pvt Ltd')
        ->and($sow->body_html)->toContain('INR 250,000.00')
        ->and($sow->body_html)->toContain('50% upfront')
        ->and($sow->body_html)->toContain('1 Nov 2026')
        ->and($sow->body_html)->toContain('15 Dec 2026')
        ->and($sow->body_html)->toContain('No milestones have been defined yet.')
        ->and($sow->body_html)->not->toContain('{{')
        ->and($sow->templateId())->not->toBeNull();
});

it('escapes substituted values so client content cannot inject markup', function () {
    $this->project->update(['title' => '<script>alert(1)</script>']);

    expect(draftSow()->body_html)->not->toContain('<script>')->toContain('&lt;script&gt;');
});

it('renders an unset value as To be confirmed and leaves unknown placeholders visible', function () {
    $this->project->update(['contract_value' => null, 'start_date' => null]);
    AgreementTemplate::query()->where('type', 'sow_template')->update(['body_html' => '<p>{{total_value}} {{start_date}} {{mystery}}</p>']);

    expect(draftSow()->body_html)->toBe('<p>To be confirmed To be confirmed {{mystery}}</p>');
});

it('refuses to create a SOW when no SOW template is published', function () {
    AgreementTemplate::query()->where('type', 'sow_template')->update(['is_active' => false]);

    expect(fn () => draftSow())->toThrow(ActionBlocked::class, 'SOW template');
});

it('refuses a second draft while one exists', function () {
    draftSow();

    expect(fn () => draftSow())->toThrow(ActionBlocked::class, 'draft SOW already exists');
});

it('sends a draft SOW, moves the project to sow_sent and notifies the client managers', function () {
    $manager = clientUser($this->org, OrgRole::Manager);
    $viewer = clientUser($this->org, OrgRole::Viewer);
    $sow = app(SendSow::class)(draftSow(), $this->dm);

    expect($sow->status)->toBe(SowStatus::Sent)
        ->and($sow->sent_at)->not->toBeNull()
        ->and($this->project->refresh()->status)->toBe(ProjectStatus::SowSent);

    Notification::assertSentTo([$this->owner, $manager], SowSent::class);
    Notification::assertNotSentTo($viewer, SowSent::class);
});

it('cannot send a SOW twice', function () {
    $sow = app(SendSow::class)(draftSow(), $this->dm);

    expect(fn () => app(SendSow::class)($sow, $this->dm))->toThrow(IllegalTransition::class);
});

it('cannot send a SOW once the project is past the proposal stage', function () {
    $sow = draftSow();
    $this->project->update(['status' => ProjectStatus::Staffing]);

    expect(fn () => app(SendSow::class)($sow, $this->dm))->toThrow(ActionBlocked::class);
    expect($sow->refresh()->status)->toBe(SowStatus::Draft);
});

it('edits a draft SOW in place without a new version', function () {
    $sow = draftSow();

    $same = app(UpdateSow::class)($sow, $this->dm, ['payment_terms' => 'Net 30', 'title' => 'SOW v1 revised']);

    expect($same->is($sow))->toBeTrue()
        ->and($same->version)->toBe(1)
        ->and($same->payment_terms)->toBe('Net 30');
    expect(Sow::query()->count())->toBe(1);
});

it('re-renders the body from the stored template when asked', function () {
    $sow = draftSow();
    $this->project->update(['title' => 'Renamed dashboard']);

    $updated = app(UpdateSow::class)($sow, $this->dm, ['payment_terms' => 'Net 45', 'rerender' => true]);

    expect($updated->body_html)->toContain('Renamed dashboard')->toContain('Net 45');
});

it('creates version 2 and supersedes version 1 when a sent SOW is edited, keeping the project at sow_sent (SCR-007)', function () {
    $v1 = app(SendSow::class)(draftSow(), $this->dm);

    $v2 = app(UpdateSow::class)($v1, $this->dm, ['payment_terms' => 'Revised terms']);

    expect($v2->version)->toBe(2)
        ->and($v2->status)->toBe(SowStatus::Draft)
        ->and($v2->payment_terms)->toBe('Revised terms')
        ->and($v1->refresh()->status)->toBe(SowStatus::Superseded)
        ->and($v1->superseded_by)->toBe($v2->id)
        ->and($this->project->refresh()->status)->toBe(ProjectStatus::SowSent);

    // The new version goes out through the normal send action and can then be accepted.
    app(SendSow::class)($v2, $this->dm);
    expect(app(AcceptSow::class)($v2, $this->owner, true)->status)->toBe(SowStatus::Accepted);
});

it('does not let a superseded SOW be accepted', function () {
    $v1 = app(SendSow::class)(draftSow(), $this->dm);
    app(UpdateSow::class)($v1, $this->dm, ['payment_terms' => 'Revised']);

    expect(fn () => app(AcceptSow::class)($v1->refresh(), $this->owner, true))->toThrow(IllegalTransition::class);
});

it('keeps an accepted SOW immutable', function () {
    $sow = app(SendSow::class)(draftSow(), $this->dm);
    app(AcceptSow::class)($sow, $this->owner, true);

    expect(fn () => app(UpdateSow::class)($sow->refresh(), $this->dm, ['payment_terms' => 'Sneaky change']))
        ->toThrow(ActionBlocked::class, 'can no longer be changed');
    expect(fn () => draftSow())->toThrow(ActionBlocked::class);
    expect($sow->refresh()->payment_terms)->not->toBe('Sneaky change');
});

it('records acceptance on the SOW and in agreement_acceptances, then moves the project to sow_accepted', function () {
    $sow = app(SendSow::class)(draftSow(), $this->dm);

    $accepted = app(AcceptSow::class)($sow, $this->owner, true, '203.0.113.7', 'Mozilla/5.0 Test');

    expect($accepted->status)->toBe(SowStatus::Accepted)
        ->and($accepted->accepted_by)->toBe($this->owner->id)
        ->and($accepted->accepted_at)->not->toBeNull()
        ->and($accepted->accepted_ip)->toBe('203.0.113.7')
        ->and($accepted->accepted_user_agent)->toBe('Mozilla/5.0 Test')
        ->and($this->project->refresh()->status)->toBe(ProjectStatus::SowAccepted);

    $row = AgreementAcceptance::query()->where('project_id', $this->project->id)->sole();
    expect($row->user_id)->toBe($this->owner->id)
        ->and($row->organization_id)->toBe($this->org->id)
        ->and($row->template_id)->toBe($sow->templateId())
        ->and($row->ip)->toBe('203.0.113.7')
        ->and($row->user_agent)->toBe('Mozilla/5.0 Test')
        ->and($row->accepted_at)->not->toBeNull();

    Notification::assertSentTo($this->dm, SowAccepted::class);
    Notification::assertSentTo($this->owner, SowAccepted::class);
});

it('requires explicit confirmation to accept', function () {
    $sow = app(SendSow::class)(draftSow(), $this->dm);

    expect(fn () => app(AcceptSow::class)($sow, $this->owner, false))->toThrow(ActionBlocked::class, 'confirm');
    expect($sow->refresh()->status)->toBe(SowStatus::Sent);
});

it('blocks acceptance until the owner has accepted the Client MSA', function () {
    $org = Organization::factory()->verified()->create();
    $owner = clientUser($org, OrgRole::Owner);
    $project = Project::factory()->create(['organization_id' => $org->id]);
    $sow = app(SendSow::class)(app(CreateSow::class)($project, $this->dm), $this->dm);

    expect(fn () => app(AcceptSow::class)($sow, $owner, true))->toThrow(ActionBlocked::class, 'Client MSA');
    expect($sow->refresh()->status)->toBe(SowStatus::Sent);
});

it('only lets the owner of the project organization accept', function () {
    $sow = app(SendSow::class)(draftSow(), $this->dm);
    $manager = clientUser($this->org, OrgRole::Manager);
    $viewer = clientUser($this->org, OrgRole::Viewer);
    $outsider = acceptClientMsa(Organization::factory()->verified()->create());

    expect(fn () => app(AcceptSow::class)($sow, $manager, true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AcceptSow::class)($sow, $viewer, true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AcceptSow::class)($sow, $outsider, true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AcceptSow::class)($sow, $this->dm, true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AcceptSow::class)($sow, contributorUser(), true))->toThrow(AuthorizationException::class);
});

it('cannot accept the same SOW twice', function () {
    $sow = app(SendSow::class)(draftSow(), $this->dm);
    app(AcceptSow::class)($sow, $this->owner, true);

    expect(fn () => app(AcceptSow::class)($sow->refresh(), $this->owner, true))->toThrow(IllegalTransition::class);
    expect(AgreementAcceptance::query()->where('project_id', $this->project->id)->count())->toBe(1);
});

it('does not let a client accept a draft SOW', function () {
    $sow = draftSow();

    expect(fn () => app(AcceptSow::class)($sow, $this->owner, true))->toThrow(AuthorizationException::class);
});

it('keeps drafts internal and limits creation and sending to admins with SOW write access', function () {
    $sow = draftSow();
    $finance = adminUser(AdminRole::FinanceOps);
    $community = adminUser(AdminRole::CommunityManager);

    expect($this->owner->can('view', $sow))->toBeFalse()
        ->and($finance->can('view', $sow))->toBeTrue()
        ->and($community->can('view', $sow))->toBeFalse()
        ->and(fn () => app(CreateSow::class)($this->project, $finance))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SendSow::class)($sow, $this->owner))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateSow::class)($sow, $finance, ['title' => 'x']))->toThrow(AuthorizationException::class);
});

it('writes an activity entry for every SOW step', function () {
    $sow = app(SendSow::class)(draftSow(), $this->dm);
    app(AcceptSow::class)($sow, $this->owner, true);

    $events = Activity::query()->where('log_name', 'sow')->pluck('event')->all();
    expect($events)->toEqualCanonicalizing(['created', 'sent', 'accepted']);
});
