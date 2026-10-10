<?php

use App\Enums\AdminRole;
use App\Enums\BriefStatus;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\SowStatus;
use App\Filament\Admin\Resources\Briefs\Pages\EditBrief;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\RelationManagers\SowsRelationManager;
use App\Livewire\Client\Agreements\AcceptMsa;
use App\Livewire\Client\Briefs\BriefForm;
use App\Livewire\Client\Projects\ProjectShow;
use App\Models\AgreementAcceptance;
use App\Models\Brief;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Skill;
use App\Models\Sow;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('runs brief to accepted SOW through the real client pages and admin actions', function () {
    Storage::fake('local');
    publishAgreements();

    $org = Organization::factory()->verified()->create(['name' => 'Acme Foods']);
    $owner = clientUser($org, OrgRole::Owner);
    $dm = adminUser(AdminRole::DeliveryManager);
    $type = ProjectType::factory()->create();
    $skill = Skill::factory()->create();

    // 1. The owner is told to accept the MSA, and cannot submit a brief until they do.
    asUser($owner)->get('/app/client')->assertOk()->assertSee('accept the Client MSA');

    // 2. The owner accepts the Client MSA.
    Livewire::actingAs($owner)->test(AcceptMsa::class)->set('confirmed', true)->call('accept')->assertHasNoErrors();
    asUser($owner)->get('/app/client')->assertOk()->assertDontSee('accept the Client MSA');

    // 3. The owner fills the guided form and submits.
    Livewire::actingAs($owner)->test(BriefForm::class)
        ->set('form.title', 'Regional sales dashboard')
        ->set('form.problem', 'Regional sales numbers live in five spreadsheets and nobody trusts them.')
        ->set('form.desired_outcome', 'One dashboard the leadership team checks every Monday.')
        ->call('next')
        ->set('form.project_type_id', $type->id)->set('form.skill_ids', [$skill->id])->call('next')
        ->set('form.budget_band', '3l_10l')->set('form.timeline_band', '1_3m')->call('next')
        ->set('form.confidentiality', 'standard')->call('next')
        ->call('submit')->assertHasNoErrors();

    $brief = Brief::query()->sole();
    expect($brief->status)->toBe(BriefStatus::Submitted);
    expect($dm->notifications()->where('data->title', 'New brief submitted: Regional sales dashboard')->exists())->toBeTrue();

    // 4. The delivery manager reviews, scopes and converts it.
    $page = Livewire::actingAs($dm)->test(EditBrief::class, ['record' => $brief->getRouteKey()]);
    $page->callAction('markInReview')->callAction('markScoped');
    $page->callAction('convert', ['title' => 'Regional sales dashboard', 'delivery_manager_id' => $dm->id, 'contract_value' => 240000]);

    $project = Project::query()->sole();
    expect($brief->refresh()->status)->toBe(BriefStatus::Converted)
        ->and($project->brief_id)->toBe($brief->id)
        ->and($project->code)->toBe('GRB-'.now()->year.'-0001')
        ->and($project->status)->toBe(ProjectStatus::Draft);

    // The client still cannot see the draft project.
    asUser($owner)->get("/app/client/projects/{$project->uuid}")->assertForbidden();

    // 5. The delivery manager drafts and sends the SOW.
    $sows = Livewire::actingAs($dm)->test(SowsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class]);
    $sows->callAction(TestAction::make('newSow')->table(), ['total_value' => 240000, 'payment_terms' => '50% on acceptance, 50% on delivery']);
    $sow = Sow::query()->sole();
    expect($sow->body_html)->toContain('Acme Foods')->toContain('Regional sales dashboard')->toContain('INR 240,000.00');

    $sows->callAction(TestAction::make('sendSow')->table($sow));
    expect($sow->refresh()->status)->toBe(SowStatus::Sent)->and($project->refresh()->status)->toBe(ProjectStatus::SowSent);
    expect($owner->notifications()->where('data->title', 'like', 'Statement of Work ready%')->exists())->toBeTrue();

    // 6. The owner reads it, and accepts it with the checkbox and button.
    asUser($owner)->get("/app/client/projects/{$project->uuid}")->assertOk()->assertSee('Regional sales dashboard')->assertSee('Accept Statement of Work');
    asUser($owner)->get("/app/client/projects/{$project->uuid}/sows/1/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    Livewire::actingAs($owner)->test(ProjectShow::class, ['project' => $project->fresh()])
        ->call('accept', 1)->assertHasErrors('accept')
        ->set('confirmed', true)->call('accept', 1)->assertHasNoErrors();

    // 7. Everything is recorded and notified.
    expect($project->refresh()->status)->toBe(ProjectStatus::SowAccepted)
        ->and($sow->refresh()->status)->toBe(SowStatus::Accepted)
        ->and($sow->accepted_by)->toBe($owner->id);
    expect(AgreementAcceptance::query()->count())->toBe(2); // Client MSA and SOW
    expect($dm->notifications()->where('data->title', 'Statement of Work accepted: Regional sales dashboard')->exists())->toBeTrue();

    $log = Activity::query()->whereIn('log_name', ['brief', 'project', 'sow', 'agreement'])->get()->map(fn ($a) => $a->log_name.':'.$a->event)->all();
    expect($log)->toContain('brief:submitted', 'brief:in_review', 'brief:scoped', 'brief:converted')
        ->toContain('project:created', 'project:sow_sent', 'project:sow_accepted')
        ->toContain('sow:created', 'sow:sent', 'sow:accepted')
        ->toContain('agreement:accepted');

    // The accepted SOW can no longer be changed, and staffing is the next admin step.
    Livewire::actingAs($dm)->test(EditProject::class, ['record' => $project->getRouteKey()])->assertActionVisible('startStaffing');
});
