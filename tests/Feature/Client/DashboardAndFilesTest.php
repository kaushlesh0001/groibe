<?php

use App\Actions\Files\AttachPrivateFile;
use App\Enums\BriefStatus;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Livewire\Client\Briefs\BriefForm;
use App\Models\Brief;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    publishAgreements();
    $this->org = Organization::factory()->verified()->create();
    $this->owner = acceptClientMsa($this->org);
});

it('shows what needs the client attention without lazy loading', function () {
    Project::factory()->status(ProjectStatus::SowSent)->create(['organization_id' => $this->org->id, 'title' => 'Awaiting SOW project']);
    Project::factory()->count(2)->status(ProjectStatus::Staffing)->create(['organization_id' => $this->org->id]);
    Brief::factory()->status(BriefStatus::NeedsInfo)->create(['organization_id' => $this->org->id, 'title' => 'Question brief']);
    Brief::factory()->status(BriefStatus::InReview)->create(['organization_id' => $this->org->id]);

    $this->actingAs($this->owner)->get('/app/client')->assertOk()
        ->assertSee('Awaiting SOW project')->assertSee('Question brief')->assertSee('Projects in progress');
});

it('keeps other organizations out of the dashboard, lists and counts', function () {
    $other = Organization::factory()->verified()->create();
    Project::factory()->status(ProjectStatus::SowSent)->create(['organization_id' => $other->id, 'title' => 'Their project']);
    Brief::factory()->status(BriefStatus::NeedsInfo)->create(['organization_id' => $other->id, 'title' => 'Their brief']);

    $this->actingAs($this->owner)->get('/app/client')->assertOk()->assertDontSee('Their project')->assertDontSee('Their brief');
});

it('tells a manager that only the owner can accept the MSA', function () {
    $org = Organization::factory()->verified()->create();
    clientUser($org, OrgRole::Owner);
    $manager = clientUser($org, OrgRole::Manager);

    $this->actingAs($manager)->get('/app/client')->assertOk()->assertSee('organization owner needs to accept');
});

it('lists many briefs and projects without lazy loading', function () {
    Brief::factory()->count(12)->create(['organization_id' => $this->org->id, 'project_type_id' => ProjectType::factory()]);
    Project::factory()->count(12)->status(ProjectStatus::Active)->create(['organization_id' => $this->org->id, 'project_type_id' => ProjectType::factory()]);

    $this->actingAs($this->owner)->get('/app/client/briefs')->assertOk();
    $this->actingAs($this->owner)->get('/app/client/projects')->assertOk();
});

it('lets the client remove their own brief file while editing', function () {
    $brief = Brief::factory()->create(['organization_id' => $this->org->id]);
    $media = app(AttachPrivateFile::class)($brief, UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'), $this->owner);

    Livewire::actingAs($this->owner)->test(BriefForm::class, ['brief' => $brief])->call('removeFile', $media->id);

    expect($brief->refresh()->getMedia(Brief::FILES))->toHaveCount(0);
});

it('does not let a client remove a file from another brief or another organization', function () {
    $mine = Brief::factory()->create(['organization_id' => $this->org->id]);
    $otherOrg = Organization::factory()->verified()->create();
    $otherOwner = clientUser($otherOrg, OrgRole::Owner);
    $theirs = Brief::factory()->create(['organization_id' => $otherOrg->id]);
    $theirFile = app(AttachPrivateFile::class)($theirs, UploadedFile::fake()->create('secret.pdf', 5, 'application/pdf'), $otherOwner);

    // Another organization's file id, through my own brief.
    Livewire::actingAs($this->owner)->test(BriefForm::class, ['brief' => $mine])->call('removeFile', $theirFile->id)->assertNotFound();
    expect($theirs->refresh()->getMedia(Brief::FILES))->toHaveCount(1);
});

it('does not let a viewer or a submitted brief change files', function () {
    $brief = Brief::factory()->create(['organization_id' => $this->org->id]);
    $media = app(AttachPrivateFile::class)($brief, UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'), $this->owner);
    $viewer = clientUser($this->org, OrgRole::Viewer);

    Livewire::actingAs($viewer)->test(BriefForm::class, ['brief' => $brief])->assertForbidden();

    $brief->forceFill(['status' => BriefStatus::Submitted])->save();
    Livewire::actingAs($this->owner)->test(BriefForm::class, ['brief' => $brief->fresh()])->assertForbidden();
    expect($brief->refresh()->getMedia(Brief::FILES))->toHaveCount(1);
});
