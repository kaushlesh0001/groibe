<?php

use App\Actions\Sows\CreateSow;
use App\Actions\Sows\SendSow;
use App\Actions\Sows\UpdateSow;
use App\Enums\AdminRole;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\SowStatus;
use App\Livewire\Client\Projects\ProjectShow;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sow;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    publishAgreements();
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->org = Organization::factory()->verified()->create();
    $this->owner = acceptClientMsa($this->org);
    $this->otherOrg = Organization::factory()->verified()->create();
    $this->otherOwner = acceptClientMsa($this->otherOrg);
    $this->project = Project::factory()->create(['organization_id' => $this->org->id, 'title' => 'Sales dashboard']);
});

function sentSow(Project $project): Sow
{
    return app(SendSow::class)(app(CreateSow::class)($project, test()->dm), test()->dm);
}

it('hides draft projects from the client and shows them once the SOW is sent', function () {
    $this->actingAs($this->owner)->get('/app/client/projects')->assertOk()->assertDontSee('Sales dashboard');
    $this->actingAs($this->owner)->get("/app/client/projects/{$this->project->uuid}")->assertForbidden();

    sentSow($this->project);

    $this->actingAs($this->owner)->get('/app/client/projects')->assertOk()->assertSee('Sales dashboard');
    $this->actingAs($this->owner)->get("/app/client/projects/{$this->project->uuid}")->assertOk()->assertSee('Statement of Work');
});

it('denies another organization every project and SOW route', function () {
    $sow = sentSow($this->project);

    $this->actingAs($this->otherOwner)->get("/app/client/projects/{$this->project->uuid}")->assertForbidden();
    $this->actingAs($this->otherOwner)->get("/app/client/projects/{$this->project->uuid}/sows/{$sow->version}/print")->assertForbidden();
    $this->actingAs($this->otherOwner)->get("/app/client/projects/{$this->project->uuid}/sows/{$sow->version}/pdf")->assertForbidden();
    $this->actingAs($this->otherOwner)->get('/app/client/projects')->assertOk()->assertDontSee('Sales dashboard');

    Livewire::actingAs($this->otherOwner)->test(ProjectShow::class, ['project' => $this->project])->assertForbidden();
});

it('never shows a draft SOW to the client, even by direct URL', function () {
    $draft = app(CreateSow::class)($this->project, $this->dm);
    $this->project->update(['status' => ProjectStatus::SowSent]);

    $this->actingAs($this->owner)->get("/app/client/projects/{$this->project->uuid}/sows/{$draft->version}/print")->assertForbidden();
    $this->actingAs($this->owner)->get("/app/client/projects/{$this->project->uuid}/sows/{$draft->version}/pdf")->assertForbidden();
    $this->actingAs($this->owner)->get("/app/client/projects/{$this->project->uuid}")->assertOk()->assertDontSee($draft->title);
});

it('serves the SOW print view and a real PDF to the owning organization', function () {
    $sow = sentSow($this->project);

    $this->actingAs($this->owner)->get("/app/client/projects/{$this->project->uuid}/sows/{$sow->version}/print")
        ->assertOk()->assertSee('Sales dashboard')->assertSee($this->project->code);

    $response = $this->actingAs($this->owner)->get("/app/client/projects/{$this->project->uuid}/sows/{$sow->version}/pdf");
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-')
        ->and($response->headers->get('Content-Disposition'))->toContain($this->project->code.'-SOW-v1.pdf');
});

it('returns 404 for a SOW version that does not exist', function () {
    sentSow($this->project);

    $this->actingAs($this->owner)->get("/app/client/projects/{$this->project->uuid}/sows/9/print")->assertNotFound();
});

it('lets the owner accept the SOW through the page and records it', function () {
    $sow = sentSow($this->project);

    Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $this->project->fresh()])
        ->call('accept', $sow->version)->assertHasErrors('accept')
        ->set('confirmed', true)
        ->call('accept', $sow->version)->assertHasNoErrors();

    expect($sow->refresh()->status)->toBe(SowStatus::Accepted)
        ->and($sow->accepted_by)->toBe($this->owner->id)
        ->and($sow->accepted_ip)->not->toBeNull()
        ->and($this->project->refresh()->status)->toBe(ProjectStatus::SowAccepted);
});

it('does not let a manager or viewer accept, and tells them why', function (OrgRole $role) {
    $sow = sentSow($this->project);
    $member = clientUser($this->org, $role);

    $this->actingAs($member)->get("/app/client/projects/{$this->project->uuid}")
        ->assertOk()->assertSee('Only the organization owner can accept')->assertDontSee('Accept Statement of Work');

    Livewire::actingAs($member)->test(ProjectShow::class, ['project' => $this->project->fresh()])
        ->set('confirmed', true)->call('accept', $sow->version)->assertHasErrors('accept');

    expect($sow->refresh()->status)->toBe(SowStatus::Sent);
})->with([OrgRole::Manager, OrgRole::Viewer]);

it('asks the owner to accept the MSA first when it is missing', function () {
    $org = Organization::factory()->verified()->create();
    $owner = clientUser($org, OrgRole::Owner);
    $project = Project::factory()->create(['organization_id' => $org->id]);
    sentSow($project);

    $this->actingAs($owner)->get("/app/client/projects/{$project->uuid}")
        ->assertOk()->assertSee('accept the Client MSA')->assertDontSee('Accept Statement of Work');
});

it('keeps superseded versions readable as history but not acceptable', function () {
    $v1 = sentSow($this->project);
    $v2 = app(UpdateSow::class)($v1, $this->dm, ['payment_terms' => 'Net 45']);
    app(SendSow::class)($v2, $this->dm);

    $page = $this->actingAs($this->owner)->get("/app/client/projects/{$this->project->uuid}");
    $page->assertOk()->assertSee('Version 2')->assertSee('Version 1')->assertSee('Superseded');
});

it('gives clients no way to reach admin SOW or project screens', function () {
    sentSow($this->project);

    $this->actingAs($this->owner)->get('/admin/projects')->assertForbidden();
    $this->actingAs($this->owner)->get('/admin/briefs')->assertForbidden();
    $this->actingAs($this->owner)->get('/admin/agreement-templates')->assertForbidden();
});
