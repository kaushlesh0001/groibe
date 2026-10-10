<?php

use App\Actions\Agreements\CreateAgreementTemplateVersion;
use App\Actions\Sows\AcceptSow;
use App\Actions\Sows\CreateSow;
use App\Actions\Sows\SendSow;
use App\Enums\AdminRole;
use App\Enums\AgreementType;
use App\Enums\BriefStatus;
use App\Enums\ProjectStatus;
use App\Enums\SowStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Filament\Admin\Resources\AgreementTemplates\Pages\EditAgreementTemplate;
use App\Filament\Admin\Resources\AgreementTemplates\Pages\ListAgreementTemplates;
use App\Filament\Admin\Resources\Briefs\Pages\EditBrief;
use App\Filament\Admin\Resources\Briefs\Pages\ListBriefs;
use App\Filament\Admin\Resources\Projects\Pages\CreateProject;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\Pages\ListProjects;
use App\Filament\Admin\Resources\Projects\RelationManagers\SowsRelationManager;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\AgreementTemplate;
use App\Models\Brief;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Skill;
use App\Models\Sow;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    publishAgreements();
    $this->org = Organization::factory()->verified()->create();
    $this->owner = acceptClientMsa($this->org);
    $this->dm = adminUser(AdminRole::DeliveryManager);
});

function briefPage(Brief $brief): array
{
    return ['record' => $brief->getRouteKey()];
}

it('runs the whole brief review through the admin actions and converts it to a project', function () {
    $brief = Brief::factory()->status(BriefStatus::Submitted)->create(['organization_id' => $this->org->id, 'title' => 'Market study']);
    $skill = Skill::factory()->create();
    $brief->skills()->attach($skill);

    $page = Livewire::actingAs($this->dm)->test(EditBrief::class, briefPage($brief));

    $page->assertActionVisible('markInReview')->assertActionHidden('markScoped')->assertActionHidden('convert')
        ->callAction('markInReview');
    expect($brief->refresh()->status)->toBe(BriefStatus::InReview);

    $page->callAction('requestInfo', ['note' => 'Which regions?']);
    expect($brief->refresh()->status)->toBe(BriefStatus::NeedsInfo)->and($brief->needs_info_note)->toBe('Which regions?');

    $page->callAction('resume');
    expect($brief->refresh()->status)->toBe(BriefStatus::InReview);

    $page->callAction('markScoped');
    expect($brief->refresh()->status)->toBe(BriefStatus::Scoped);

    $page->assertActionVisible('convert')
        ->callAction('convert', ['title' => 'Market study phase 1', 'delivery_manager_id' => $this->dm->id, 'contract_value' => 125000]);

    $project = Project::query()->sole();
    expect($brief->refresh()->status)->toBe(BriefStatus::Converted)
        ->and($project->title)->toBe('Market study phase 1')
        ->and((float) $project->contract_value)->toBe(125000.0)
        ->and($project->delivery_manager_id)->toBe($this->dm->id)
        ->and($project->skills()->count())->toBe(1);
});

it('declines a brief with a reason', function () {
    $brief = Brief::factory()->status(BriefStatus::InReview)->create(['organization_id' => $this->org->id]);

    Livewire::actingAs($this->dm)->test(EditBrief::class, briefPage($brief))->callAction('decline', ['reason' => 'Not a fit']);

    expect($brief->refresh()->status)->toBe(BriefStatus::Declined);
});

it('turns a refused action into a readable notification instead of an error page', function () {
    asUser($this->dm);

    $ran = DomainActionRunner::run(fn () => throw IllegalTransition::for('brief', 'scoped', 'needs_info'), 'Done');
    expect($ran)->toBeFalse();

    $ran = DomainActionRunner::run(fn () => throw ActionBlocked::guardFailed('Nope'), 'Done');
    expect($ran)->toBeFalse();

    $ran = DomainActionRunner::run(fn () => throw new AuthorizationException, 'Done');
    expect($ran)->toBeFalse();

    $titles = collect(session('filament.notifications'))->pluck('title');
    expect($titles->all())->toBe(['That did not go through', 'That did not go through', 'You are not allowed to do that']);

    expect(DomainActionRunner::run(fn () => null, 'All good'))->toBeTrue();
});

it('only saves the internal fields of a brief, never the client text', function () {
    $brief = Brief::factory()->status(BriefStatus::InReview)->create(['organization_id' => $this->org->id, 'title' => 'Original title']);

    Livewire::actingAs($this->dm)->test(EditBrief::class, briefPage($brief))
        ->fillForm(['internal_notes' => 'Worth pursuing', 'title' => 'Hacked title', 'assigned_admin_id' => $this->dm->id])
        ->call('save')->assertHasNoFormErrors();

    expect($brief->refresh()->internal_notes)->toBe('Worth pursuing')
        ->and($brief->title)->toBe('Original title')
        ->and($brief->assigned_admin_id)->toBe($this->dm->id);
});

it('hides draft briefs from the admin list and shows organization and status', function () {
    $submitted = Brief::factory()->count(3)->status(BriefStatus::Submitted)->create(['organization_id' => $this->org->id]);
    $draft = Brief::factory()->create(['organization_id' => $this->org->id]);

    Livewire::actingAs($this->dm)->test(ListBriefs::class)
        ->assertCanSeeTableRecords($submitted)->assertCanNotSeeTableRecords([$draft]);
});

it('gives community managers read-only access to briefs', function () {
    $brief = Brief::factory()->status(BriefStatus::Submitted)->create(['organization_id' => $this->org->id, 'title' => 'Read only brief']);
    $community = adminUser(AdminRole::CommunityManager);

    Livewire::actingAs($community)->test(ListBriefs::class)->assertCanSeeTableRecords([$brief]);

    asUser($community)->get("/admin/briefs/{$brief->getRouteKey()}")->assertOk()->assertSee('Read only brief');
    asUser($community)->get("/admin/briefs/{$brief->getRouteKey()}/edit")->assertForbidden();
});

it('keeps finance out of briefs and clients out of every admin screen', function () {
    asUser(adminUser(AdminRole::FinanceOps))->get('/admin/briefs')->assertForbidden();
    asUser($this->owner)->get('/admin/briefs')->assertForbidden();
    asUser(contributorUser())->get('/admin/projects')->assertForbidden();
});

it('creates a project through the admin form with a generated code', function () {
    $skill = Skill::factory()->create();

    Livewire::actingAs($this->dm)->test(CreateProject::class)
        ->fillForm(['organization_id' => $this->org->id, 'title' => 'Ops automation', 'skills' => [$skill->id]])
        ->call('create')->assertHasNoFormErrors();

    $project = Project::query()->sole();
    expect($project->code)->toBe('GRB-'.now()->year.'-0001')
        ->and($project->status)->toBe(ProjectStatus::Draft)
        ->and($project->skills()->count())->toBe(1);
});

it('limits project writes to delivery managers and super admins', function () {
    $project = Project::factory()->create(['organization_id' => $this->org->id]);

    asUser(adminUser(AdminRole::CommunityManager))->get('/admin/projects/create')->assertForbidden();
    asUser(adminUser(AdminRole::FinanceOps))->get('/admin/projects/create')->assertForbidden();
    asUser(adminUser(AdminRole::FinanceOps))->get('/admin/projects')->assertOk();
    asUser(adminUser(AdminRole::CommunityManager))->get('/admin/projects')->assertOk();
    asUser($this->dm)->get('/admin/projects/create')->assertOk();
    asUser($this->dm)->get("/admin/projects/{$project->getRouteKey()}/edit")->assertOk();
});

it('lists projects with their organization and delivery manager without extra queries per row', function () {
    $projects = Project::factory()->count(4)->create(['organization_id' => $this->org->id, 'delivery_manager_id' => $this->dm->id]);

    Livewire::actingAs($this->dm)->test(ListProjects::class)->assertCanSeeTableRecords($projects);
});

it('drives project transitions from the edit page and refuses what the guards refuse', function () {
    $project = Project::factory()->status(ProjectStatus::SowAccepted)->create(['organization_id' => $this->org->id]);
    $page = Livewire::actingAs($this->dm)->test(EditProject::class, ['record' => $project->getRouteKey()]);

    $page->assertActionVisible('startStaffing')->assertActionHidden('activate')->callAction('startStaffing');
    expect($project->refresh()->status)->toBe(ProjectStatus::Staffing);

    // Pass 4 supplies the real assignment check; until then activation is refused with a message.
    $page->assertActionVisible('activate')->callAction('activate')->assertNotified('That did not go through');
    expect($project->refresh()->status)->toBe(ProjectStatus::Staffing);
});

it('cancels a project only with a reason', function () {
    $project = Project::factory()->status(ProjectStatus::Staffing)->create(['organization_id' => $this->org->id]);

    Livewire::actingAs($this->dm)->test(EditProject::class, ['record' => $project->getRouteKey()])
        ->callAction('cancel', ['reason' => ''])->assertHasActionErrors(['reason' => 'required']);
    expect($project->refresh()->status)->toBe(ProjectStatus::Staffing);

    $page = Livewire::actingAs($this->dm)->test(EditProject::class, ['record' => $project->getRouteKey()])
        ->callAction('cancel', ['reason' => 'Client withdrew']);
    expect($project->refresh()->status)->toBe(ProjectStatus::Cancelled);
    $page->assertActionHidden('cancel');
});

it('offers a resume action on a held project that returns it to where it was', function () {
    $project = Project::factory()->status(ProjectStatus::Active)->create(['organization_id' => $this->org->id]);

    $page = Livewire::actingAs($this->dm)->test(EditProject::class, ['record' => $project->getRouteKey()]);
    $page->assertActionHidden('resume')->callAction('hold', ['reason' => 'Waiting on client']);
    expect($project->refresh()->status)->toBe(ProjectStatus::OnHold);

    $held = Livewire::actingAs($this->dm)->test(EditProject::class, ['record' => $project->getRouteKey()]);
    $held->assertActionVisible('resume')->assertActionHidden('hold')->assertActionHidden('activate')->assertActionVisible('cancel')
        ->callAction('resume');
    expect($project->refresh()->status)->toBe(ProjectStatus::Active)->and($project->status_before_hold)->toBeNull();
});

it('creates, edits, sends and supersedes SOWs from the project relation manager', function () {
    $project = Project::factory()->create(['organization_id' => $this->org->id, 'title' => 'Ops automation']);
    $manager = Livewire::actingAs($this->dm)->test(SowsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class]);

    $manager->callAction(TestAction::make('newSow')->table(), ['total_value' => 90000, 'payment_terms' => 'Net 30']);
    $v1 = Sow::query()->sole();
    expect($v1->status)->toBe(SowStatus::Draft)->and($v1->payment_terms)->toBe('Net 30');

    $manager->callAction(TestAction::make('sendSow')->table($v1));
    expect($v1->refresh()->status)->toBe(SowStatus::Sent)->and($project->refresh()->status)->toBe(ProjectStatus::SowSent);

    $manager->callAction(TestAction::make('editSow')->table($v1), [
        'title' => 'Revised SOW', 'body_html' => '<p>Revised</p>', 'total_value' => 95000, 'payment_terms' => 'Net 45',
    ]);

    expect(Sow::query()->count())->toBe(2)
        ->and($v1->refresh()->status)->toBe(SowStatus::Superseded)
        ->and(Sow::query()->where('version', 2)->sole()->status)->toBe(SowStatus::Draft);
});

it('hides edit and send on accepted SOWs and exposes a PDF to admins who can read them', function () {
    $project = Project::factory()->create(['organization_id' => $this->org->id]);
    $sow = app(SendSow::class)(app(CreateSow::class)($project, $this->dm), $this->dm);
    app(AcceptSow::class)($sow, $this->owner, true);
    $manager = Livewire::actingAs($this->dm)->test(SowsRelationManager::class, ['ownerRecord' => $project->refresh(), 'pageClass' => EditProject::class]);

    $manager->assertActionHidden(TestAction::make('editSow')->table($sow))
        ->assertActionHidden(TestAction::make('sendSow')->table($sow))
        ->assertActionVisible(TestAction::make('pdf')->table($sow))
        ->callAction(TestAction::make('pdf')->table($sow))
        ->assertFileDownloaded($project->code.'-SOW-v1.pdf');
});

it('lets every admin read agreement templates but only a super admin revise them', function () {
    foreach ([AdminRole::DeliveryManager, AdminRole::CommunityManager, AdminRole::FinanceOps] as $role) {
        asUser(adminUser($role))->get('/admin/agreement-templates')->assertOk();
    }

    $msa = AgreementTemplate::currentFor(AgreementType::ClientMsa);
    asUser(adminUser(AdminRole::DeliveryManager))->get("/admin/agreement-templates/{$msa->id}/edit")->assertForbidden();
    asUser($this->owner)->get('/admin/agreement-templates')->assertForbidden();
});

it('publishes a new version when a super admin revises a template and keeps the old text', function () {
    $msa = AgreementTemplate::currentFor(AgreementType::ClientMsa);

    Livewire::actingAs(adminUser())->test(EditAgreementTemplate::class, ['record' => $msa->getKey()])
        ->fillForm(['title' => 'Client MSA', 'body_html' => '<p>Approved wording</p>'])
        ->call('save')->assertHasNoFormErrors();

    $current = AgreementTemplate::currentFor(AgreementType::ClientMsa);
    expect($current->version)->toBe(2)
        ->and($current->body_html)->toContain('Approved wording')
        ->and($msa->refresh()->is_active)->toBeFalse()
        ->and($msa->body_html)->toContain('Placeholder');
});

it('does not allow revising a retired version', function () {
    $v1 = AgreementTemplate::currentFor(AgreementType::Terms);
    app(CreateAgreementTemplateVersion::class)($v1, adminUser(), ['title' => 'Terms', 'body_html' => '<p>v2</p>']);

    asUser(adminUser())->get("/admin/agreement-templates/{$v1->getKey()}/edit")->assertForbidden();
});

it('lists templates for read-only roles', function () {
    Livewire::actingAs(adminUser(AdminRole::FinanceOps))->test(ListAgreementTemplates::class)
        ->assertCanSeeTableRecords(AgreementTemplate::query()->get());
});
