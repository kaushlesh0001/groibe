<?php

use App\Enums\AdminRole;
use App\Enums\BriefStatus;
use App\Enums\OrgRole;
use App\Livewire\Client\Agreements\AcceptMsa;
use App\Livewire\Client\Briefs\BriefForm;
use App\Models\Brief;
use App\Models\Organization;
use App\Models\ProjectType;
use App\Models\Skill;
use App\Services\Agreements\AgreementGate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    publishAgreements();
    $this->org = Organization::factory()->verified()->create();
    $this->owner = acceptClientMsa($this->org);
    $this->otherOrg = Organization::factory()->verified()->create();
    $this->otherOwner = acceptClientMsa($this->otherOrg);
});

it('redirects guests to login and refuses other roles on every client brief route', function (string $uri) {
    $this->get($uri)->assertRedirect('/login');
    $this->actingAs(contributorUser())->get($uri)->assertForbidden();
    $this->actingAs(adminUser())->get($uri)->assertForbidden();
})->with(['/app/client/briefs', '/app/client/briefs/create', '/app/client/agreements/client-msa', '/app/client/projects']);

it('lists only the own organization briefs', function () {
    Brief::factory()->create(['organization_id' => $this->org->id, 'title' => 'Our dashboard']);
    Brief::factory()->create(['organization_id' => $this->otherOrg->id, 'title' => 'Their secret plan']);

    $this->actingAs($this->owner)->get('/app/client/briefs')
        ->assertOk()->assertSee('Our dashboard')->assertDontSee('Their secret plan');
});

it('denies viewing or editing another organization brief', function () {
    $theirs = Brief::factory()->create(['organization_id' => $this->otherOrg->id]);

    $this->actingAs($this->owner)->get("/app/client/briefs/{$theirs->uuid}")->assertForbidden();
    $this->actingAs($this->owner)->get("/app/client/briefs/{$theirs->uuid}/edit")->assertForbidden();
});

it('shows the admin question on a needs_info brief and allows editing it', function () {
    $brief = Brief::factory()->status(BriefStatus::NeedsInfo)->create(['organization_id' => $this->org->id, 'needs_info_note' => 'Which data source?']);

    $this->actingAs($this->owner)->get("/app/client/briefs/{$brief->uuid}")->assertOk()->assertSee('Which data source?');
    $this->actingAs($this->owner)->get("/app/client/briefs/{$brief->uuid}/edit")->assertOk()->assertSee('Which data source?');
});

it('does not show the internal notes or the admin question outside needs_info', function () {
    $brief = Brief::factory()->status(BriefStatus::InReview)->create([
        'organization_id' => $this->org->id,
        'internal_notes' => 'Margin looks thin',
        'needs_info_note' => 'Old question',
    ]);

    $this->actingAs($this->owner)->get("/app/client/briefs/{$brief->uuid}")
        ->assertOk()->assertDontSee('Margin looks thin')->assertDontSee('Old question');
});

it('blocks editing once a brief is submitted', function () {
    $brief = Brief::factory()->status(BriefStatus::Submitted)->create(['organization_id' => $this->org->id]);

    $this->actingAs($this->owner)->get("/app/client/briefs/{$brief->uuid}/edit")->assertForbidden();
});

it('lets viewers read briefs but not create or edit them', function () {
    $viewer = clientUser($this->org, OrgRole::Viewer);
    $brief = Brief::factory()->create(['organization_id' => $this->org->id]);

    $this->actingAs($viewer)->get('/app/client/briefs')->assertOk();
    $this->actingAs($viewer)->get("/app/client/briefs/{$brief->uuid}")->assertOk();
    $this->actingAs($viewer)->get('/app/client/briefs/create')->assertForbidden();
    $this->actingAs($viewer)->get("/app/client/briefs/{$brief->uuid}/edit")->assertForbidden();
});

function fillBriefForm($component): void
{
    $type = ProjectType::factory()->create();
    $skill = Skill::factory()->create();

    $component
        ->set('form.title', 'Sales dashboard')
        ->set('form.problem', 'We cannot see regional sales in one place today.')
        ->set('form.desired_outcome', 'One dashboard for all regions.')
        ->call('next')->assertHasNoErrors()
        ->set('form.project_type_id', $type->id)
        ->set('form.skill_ids', [$skill->id])
        ->call('next')->assertHasNoErrors()
        ->set('form.budget_band', '1l_3l')
        ->set('form.timeline_band', '1_3m')
        ->call('next')->assertHasNoErrors();
}

it('walks the guided form, saves between steps and submits the brief', function () {
    $this->actingAs($this->owner);

    $component = Livewire::test(BriefForm::class);
    fillBriefForm($component);
    $component->set('form.confidentiality', 'confidential')->call('next')->assertHasNoErrors();

    $brief = Brief::query()->sole();
    expect($brief->status)->toBe(BriefStatus::Draft)
        ->and($brief->organization_id)->toBe($this->org->id)
        ->and($brief->confidentiality->value)->toBe('confidential')
        ->and($brief->skills)->toHaveCount(1);

    $component->call('submit')->assertHasNoErrors()->assertRedirect(route('client.briefs.show', $brief));

    expect($brief->refresh()->status)->toBe(BriefStatus::Submitted)
        ->and($brief->submitted_by)->toBe($this->owner->id);
});

it('validates each step before moving on', function () {
    $this->actingAs($this->owner);

    Livewire::test(BriefForm::class)
        ->call('next')
        ->assertHasErrors(['form.title', 'form.problem', 'form.desired_outcome'])
        ->assertSet('step', 1);

    expect(Brief::query()->count())->toBe(0);
});

it('rejects an unknown budget band and past dates', function () {
    $this->actingAs($this->owner);
    $component = Livewire::test(BriefForm::class);
    $component
        ->set('form.title', 'T')->set('form.problem', str_repeat('p', 30))->set('form.desired_outcome', str_repeat('o', 20))
        ->call('next')
        ->set('form.project_type_id', ProjectType::factory()->create()->id)->call('next')
        ->set('form.budget_band', 'unlimited')->set('form.timeline_band', '1_3m')->set('form.target_start', '2001-01-01')
        ->call('next')
        ->assertHasErrors(['form.budget_band', 'form.target_start'])
        ->assertSet('step', 3);
});

it('shows a clear error and keeps the draft when the MSA has not been accepted', function () {
    $org = Organization::factory()->verified()->create();
    $owner = clientUser($org, OrgRole::Owner);
    $this->actingAs($owner);

    $component = Livewire::test(BriefForm::class);
    fillBriefForm($component);
    $component->set('form.confidentiality', 'standard')->call('next')->call('submit')->assertHasErrors('submit');

    expect(Brief::query()->sole()->status)->toBe(BriefStatus::Draft);
    $this->get('/app/client/briefs/create')->assertOk();
});

it('blocks submit for an unverified organization', function () {
    $org = Organization::factory()->create();
    $owner = acceptClientMsa($org);
    $this->actingAs($owner);

    $component = Livewire::test(BriefForm::class);
    fillBriefForm($component);
    $component->call('next')->call('submit')->assertHasErrors('submit');

    expect(Brief::query()->sole()->status)->toBe(BriefStatus::Draft);
});

it('lets the client answer a question and sends the brief back to review', function () {
    $brief = Brief::factory()->status(BriefStatus::NeedsInfo)->create([
        'organization_id' => $this->org->id,
        'needs_info_note' => 'Source?',
        'project_type_id' => ProjectType::factory()->create()->id,
    ]);
    $this->actingAs($this->owner);

    Livewire::test(BriefForm::class, ['brief' => $brief])
        ->set('form.problem', 'Updated problem statement, now with the data source named.')
        ->call('submit')->assertHasNoErrors();

    expect($brief->refresh()->status)->toBe(BriefStatus::InReview)
        ->and($brief->problem)->toContain('data source');
});

it('stores uploads privately with a randomized name and rejects bad files', function () {
    $this->actingAs($this->owner);
    $component = Livewire::test(BriefForm::class);
    fillBriefForm($component);

    $component->set('form.confidentiality', 'standard')
        ->set('uploads', [UploadedFile::fake()->create('virus.exe', 10)])
        ->call('next')->assertHasErrors('uploads.0');

    $component->set('uploads', [UploadedFile::fake()->createWithContent('Scope notes.pdf', '%PDF-1.4
1 0 obj
<< /Type /Catalog >>
endobj
trailer
<< /Root 1 0 R >>
%%EOF
')])
        ->call('next')->assertHasNoErrors();

    $media = Brief::query()->sole()->getFirstMedia(Brief::FILES);
    expect($media)->not->toBeNull()
        ->and($media->disk)->toBe('local')
        ->and($media->file_name)->not->toContain('Scope')
        ->and($media->file_name)->toEndWith('.pdf')
        ->and($media->name)->toBe('Scope notes');
    Storage::disk('local')->assertExists($media->getPathRelativeToRoot());
});

it('rejects oversized uploads', function () {
    $this->actingAs($this->owner);
    $component = Livewire::test(BriefForm::class);
    fillBriefForm($component);

    // Rejected at upload time by the Livewire temporary-upload rules (20 MB cap).
    $component->set('form.confidentiality', 'standard')
        ->set('uploads', [UploadedFile::fake()->create('big.pdf', 21 * 1024, 'application/pdf')])
        ->assertHasErrors('uploads.0');
});

it('accepts the MSA only for the owner, through the page', function () {
    $org = Organization::factory()->verified()->create();
    $owner = clientUser($org, OrgRole::Owner);
    $manager = clientUser($org, OrgRole::Manager);

    $this->actingAs($manager)->get('/app/client/agreements/client-msa')->assertOk()->assertSee('Only the organization owner');

    Livewire::actingAs($manager)->test(AcceptMsa::class)
        ->set('confirmed', true)->call('accept')->assertForbidden();

    Livewire::actingAs($owner)->test(AcceptMsa::class)
        ->call('accept')->assertHasErrors('confirmed')
        ->set('confirmed', true)->call('accept')->assertHasNoErrors();

    expect(app(AgreementGate::class)->hasAcceptedClientMsa($org))->toBeTrue();
});

it('lets an admin with brief access see the admin queue but never draft briefs', function () {
    Brief::factory()->status(BriefStatus::Submitted)->create(['organization_id' => $this->org->id, 'title' => 'Visible submitted brief']);
    Brief::factory()->create(['organization_id' => $this->org->id, 'title' => 'Hidden draft brief']);

    $this->actingAs(adminUser(AdminRole::DeliveryManager))->get('/admin/briefs')
        ->assertOk()->assertSee('Visible submitted brief')->assertDontSee('Hidden draft brief');
});
