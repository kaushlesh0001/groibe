<?php

use App\Actions\Files\AttachPrivateFile;
use App\Enums\AdminRole;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Models\Brief;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('local');
    $this->org = Organization::factory()->verified()->create();
    $this->owner = clientUser($this->org, OrgRole::Owner);
    $this->brief = Brief::factory()->create(['organization_id' => $this->org->id]);
    $this->media = app(AttachPrivateFile::class)($this->brief, UploadedFile::fake()->create('spec.pdf', 50, 'application/pdf'), $this->owner);
    $this->url = fn () => URL::temporarySignedRoute('files.show', now()->addMinutes(5), ['media' => $this->media->uuid]);
});

it('serves a private file to the owning organization through a signed link', function () {
    $this->actingAs($this->owner)->get(($this->url)())->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('spec.pdf');
});

it('serves it to admins with brief access', function () {
    $this->actingAs(adminUser(AdminRole::DeliveryManager))->get(($this->url)())->assertOk();
});

it('denies other organizations, contributors and admins without brief access', function () {
    $other = clientUser(Organization::factory()->verified()->create(), OrgRole::Owner);

    $this->actingAs($other)->get(($this->url)())->assertForbidden();
    $this->actingAs(contributorUser())->get(($this->url)())->assertForbidden();
    $this->actingAs(adminUser(AdminRole::FinanceOps))->get(($this->url)())->assertForbidden();
});

it('requires a login and a valid signature', function () {
    $signed = ($this->url)();

    $this->get($signed)->assertRedirect('/login');
    $this->actingAs($this->owner)->get(route('files.show', ['media' => $this->media->uuid]))->assertForbidden();
    $this->actingAs($this->owner)->get($signed.'x')->assertForbidden();
});

it('rejects an expired link', function () {
    $expired = URL::temporarySignedRoute('files.show', now()->subMinute(), ['media' => $this->media->uuid]);

    $this->actingAs($this->owner)->get($expired)->assertForbidden();
});

it('cannot be guessed by numeric id', function () {
    $this->actingAs($this->owner)->get('/files/'.$this->media->id)->assertNotFound();
});

it('is not reachable through any public path', function () {
    $path = $this->media->getPathRelativeToRoot();

    expect(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(config('filesystems.disks.local.url'))->toBeNull()
        ->and(config('filesystems.disks.local.visibility'))->not->toBe('public');

    // Laravel's `serve` route only answers signed temporary URLs, which this app never issues.
    expect($this->get('/storage/'.$path)->status())->toBeIn([403, 404]);
});

it('stores files under a randomized name, not the uploaded name', function () {
    expect($this->media->file_name)->toMatch('/^[0-9a-z]{26}\.pdf$/')->and($this->media->name)->toBe('spec');
});

it('refuses uploads by people who cannot edit the record', function () {
    $viewer = clientUser($this->org, OrgRole::Viewer);
    $outsider = clientUser(Organization::factory()->verified()->create(), OrgRole::Owner);
    $file = UploadedFile::fake()->create('x.pdf', 10, 'application/pdf');

    expect(fn () => app(AttachPrivateFile::class)($this->brief, $file, $viewer))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AttachPrivateFile::class)($this->brief, $file, $outsider))->toThrow(AuthorizationException::class);
});

it('denies file collections the policy does not know', function () {
    $project = Project::factory()->create(['organization_id' => $this->org->id, 'status' => ProjectStatus::SowSent]);
    $project->addMedia(UploadedFile::fake()->create('misc.pdf', 5, 'application/pdf'))->toMediaCollection('something_else', 'local');
    $media = $project->getFirstMedia('something_else');

    $url = URL::temporarySignedRoute('files.show', now()->addMinutes(5), ['media' => $media->uuid]);
    $this->actingAs($this->owner)->get($url)->assertForbidden();
});

it('serves project files only to the owning organization once the project is visible, and to project admins', function () {
    $project = Project::factory()->create(['organization_id' => $this->org->id, 'status' => ProjectStatus::Draft]);
    $project->addMedia(UploadedFile::fake()->create('plan.pdf', 5, 'application/pdf'))->toMediaCollection(Project::FILES, 'local');
    $url = URL::temporarySignedRoute('files.show', now()->addMinutes(5), ['media' => $project->getFirstMedia(Project::FILES)->uuid]);

    // Draft projects are internal, so the client is denied; admins with project access are not.
    asUser($this->owner)->get($url)->assertForbidden();
    asUser(adminUser(AdminRole::DeliveryManager))->get($url)->assertOk();
    asUser(adminUser(AdminRole::CommunityManager))->get($url)->assertOk();

    $project->update(['status' => ProjectStatus::SowSent]);
    asUser($this->owner)->get($url)->assertOk();
    asUser(clientUser(Organization::factory()->verified()->create(), OrgRole::Owner))->get($url)->assertForbidden();
    asUser(contributorUser())->get($url)->assertForbidden();
});
