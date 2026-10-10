<?php

use App\Actions\Files\AttachPrivateFile;
use App\Enums\AdminRole;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Filament\Admin\Resources\Submissions\Tables\SubmissionsTable;
use App\Livewire\Client\Deliverables\DeliverableIndex;
use App\Livewire\Contributor\Work\TaskDetail;
use App\Models\Assignment;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Submission;
use App\Models\Task;
use App\Services\Files\SignedFileUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('local');

    $this->org = Organization::factory()->verified()->create();
    $this->owner = clientUser($this->org, OrgRole::Owner);
    $this->manager = clientUser($this->org, OrgRole::Manager);
    $this->viewer = clientUser($this->org, OrgRole::Viewer);
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['organization_id' => $this->org->id, 'delivery_manager_id' => $this->dm->id]);
    $this->me = contributorIn(OnboardingState::Active);
    $this->seat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->me->id]);
    $this->milestone = Milestone::factory()->status(MilestoneStatus::InQa)->create(['project_id' => $this->project->id]);
    $this->task = Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);
    $this->submission = Submission::factory()->status(SubmissionStatus::QaApproved)->create(['task_id' => $this->task->id, 'assignment_id' => $this->seat->id]);
    $this->media = $this->submission->addMedia(UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'))->toMediaCollection(Submission::FILES);
    $this->deliverable = Deliverable::factory()->create(['milestone_id' => $this->milestone->id]);
    $this->deliverable->submissions()->attach($this->submission);

    $this->link = fn (?Media $media = null) => app(SignedFileUrl::class)->for($media ?? $this->media);
    $this->share = function (): void {
        $this->milestone->forceFill(['status' => MilestoneStatus::Shared, 'shared_at' => now()])->save();
        $this->deliverable->forceFill(['shared_at' => now()])->save();
    };
});

describe('before the work is shared with the client', function () {
    it('serves the file to the contributor who made it and to submission reviewers', function (string $who) {
        $actor = match ($who) {
            'contributor' => $this->me,
            'delivery_manager' => $this->dm,
            'super_admin' => adminUser(AdminRole::SuperAdmin),
        };

        $this->actingAs($actor)->get(($this->link)())->assertOk()
            ->assertDownload('report.pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
    })->with(['contributor', 'delivery_manager', 'super_admin']);

    it('refuses everyone else', function (string $who) {
        $actor = match ($who) {
            'other_contributor' => contributorIn(OnboardingState::Active),
            'teammate' => Assignment::factory()->active()->create(['project_id' => $this->project->id])->user,
            'community_manager' => adminUser(AdminRole::CommunityManager),
            'finance_ops' => adminUser(AdminRole::FinanceOps),
            'client_owner' => $this->owner,
            'client_manager' => $this->manager,
            'client_viewer' => $this->viewer,
            'other_org_owner' => clientUser(),
        };

        $this->actingAs($actor)->get(($this->link)())->assertForbidden();
    })->with(['other_contributor', 'teammate', 'community_manager', 'finance_ops', 'client_owner', 'client_manager', 'client_viewer', 'other_org_owner']);

    it('refuses a suspended contributor even for their own file', function () {
        $this->me->forceFill(['status' => UserStatus::Suspended])->save();

        $this->actingAs($this->me->fresh())->get(($this->link)())->assertForbidden();
    });
});

describe('once the milestone is shared', function () {
    beforeEach(fn () => ($this->share)());

    it('serves the file to every member of the client organization', function (string $who) {
        $actor = match ($who) {
            'owner' => $this->owner,
            'manager' => $this->manager,
            'viewer' => $this->viewer,
        };

        $this->actingAs($actor)->get(($this->link)())->assertOk()->assertDownload('report.pdf');
    })->with(['owner', 'manager', 'viewer']);

    it('still refuses other organizations, other contributors and roles without submission access', function (string $who) {
        $actor = match ($who) {
            'other_org_owner' => clientUser(),
            'other_contributor' => contributorIn(OnboardingState::Active),
            'community_manager' => adminUser(AdminRole::CommunityManager),
            'finance_ops' => adminUser(AdminRole::FinanceOps),
        };

        $this->actingAs($actor)->get(($this->link)())->assertForbidden();
    })->with(['other_org_owner', 'other_contributor', 'community_manager', 'finance_ops']);

    it('keeps the file available while the client has asked for changes or has accepted', function (MilestoneStatus $status) {
        $this->milestone->forceFill(['status' => $status])->save();

        $this->actingAs($this->owner)->get(($this->link)())->assertOk();
    })->with([MilestoneStatus::ClientChangesRequested, MilestoneStatus::ClientAccepted]);

    it('does not reach work that is not part of a shared deliverable', function () {
        $otherTask = Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $this->milestone->id, 'assignment_id' => $this->seat->id]);
        $notBundled = Submission::factory()->status(SubmissionStatus::QaApproved)->create(['task_id' => $otherTask->id, 'assignment_id' => $this->seat->id]);
        $notBundledMedia = $notBundled->addMedia(UploadedFile::fake()->create('left-out.pdf', 5, 'application/pdf'))->toMediaCollection(Submission::FILES);

        $earlier = Submission::factory()->status(SubmissionStatus::QaChangesRequested)->create(['task_id' => $otherTask->id, 'assignment_id' => $this->seat->id, 'version' => 2]);
        $earlierMedia = $earlier->addMedia(UploadedFile::fake()->create('old-draft.pdf', 5, 'application/pdf'))->toMediaCollection(Submission::FILES);

        $this->actingAs($this->owner)->get(($this->link)($notBundledMedia))->assertForbidden();
        $this->actingAs($this->owner)->get(($this->link)($earlierMedia))->assertForbidden();
        $this->actingAs($this->dm)->get(($this->link)($notBundledMedia))->assertOk();
    });

    it('does not reach a draft project', function () {
        $this->project->forceFill(['status' => ProjectStatus::Draft])->save();

        $this->actingAs($this->owner)->get(($this->link)())->assertForbidden();
    });

    it('needs the deliverable itself to be shared, not only the milestone', function () {
        $this->deliverable->forceFill(['shared_at' => null])->save();

        $this->actingAs($this->owner)->get(($this->link)())->assertForbidden();
    });
});

describe('work that is approved but not yet shared', function () {
    it('stays closed to the client while the milestone is in QA', function () {
        $this->deliverable->forceFill(['shared_at' => now()])->save();

        $this->actingAs($this->owner)->get(($this->link)())->assertForbidden();
    });

    it('stays closed to the client when the submission is not approved', function () {
        ($this->share)();
        $this->submission->forceFill(['status' => SubmissionStatus::Submitted])->save();

        $this->actingAs($this->owner)->get(($this->link)())->assertForbidden();
    });
});

describe('the link itself', function () {
    it('needs a login, a valid signature and an unexpired link', function () {
        $signed = ($this->link)();

        $this->get($signed)->assertRedirect('/login');
        $this->actingAs($this->me)->get(route('files.show', ['media' => $this->media->uuid]))->assertForbidden();
        $this->actingAs($this->me)->get($signed.'x')->assertForbidden();
        $this->actingAs($this->me)->get(URL::temporarySignedRoute('files.show', now()->subMinute(), ['media' => $this->media->uuid]))->assertForbidden();
    });

    it('cannot be used with a different file\'s signature', function () {
        $other = $this->submission->addMedia(UploadedFile::fake()->create('other.pdf', 5, 'application/pdf'))->toMediaCollection(Submission::FILES);
        $signedForOther = ($this->link)($other);
        $forged = str_replace($other->uuid, $this->media->uuid, $signedForOther);

        $this->actingAs($this->me)->get($forged)->assertForbidden();
    });

    it('expires after the configured time', function () {
        $url = ($this->link)();

        expect($url)->toContain('signature=')->and($url)->toContain('expires=');

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        expect((int) $query['expires'])->toBeBetween(now()->addMinutes(9)->timestamp, now()->addMinutes(11)->timestamp);
    });

    it('keeps submission files off every public path', function () {
        $path = $this->media->getPathRelativeToRoot();

        expect(Storage::disk('local')->exists($path))->toBeTrue()
            ->and($this->media->disk)->toBe('local')
            ->and($this->get('/storage/'.$path)->status())->toBeIn([403, 404]);
    });

    it('stores uploads under randomised names', function () {
        expect($this->media->file_name)->toContain('report');

        // Files attached through SubmitWork (the real path) are renamed; see ContributorWorkTest.
        $real = app(AttachPrivateFile::class)->store($this->submission, UploadedFile::fake()->create('secret-name.pdf', 5, 'application/pdf'), $this->me, Submission::FILES, 1024);

        expect($real->file_name)->toMatch('/^[0-9a-z]{26}\.pdf$/')->and($real->file_name)->not->toContain('secret-name');
    });
});

/** The links the pages hand out actually work for the person looking at the page. */
describe('links on the pages', function () {
    $hrefs = fn (string $html): array => collect(preg_match_all('/href="([^"]*\/files\/[^"]*)"/', $html, $matches) ? $matches[1] : [])
        ->map(fn (string $href) => html_entity_decode($href))
        ->all();

    it('gives the contributor working links on the task page', function () use ($hrefs) {
        $page = Livewire::actingAs($this->me)->test(TaskDetail::class, ['task' => $this->task]);

        $links = $hrefs($page->html());
        expect($links)->toHaveCount(1);

        $this->actingAs($this->me)->get($links[0])->assertOk()->assertDownload('report.pdf');
        $this->actingAs($this->owner)->get($links[0])->assertForbidden();
    });

    it('gives the reviewer working links in the QA modal', function () use ($hrefs) {
        $html = SubmissionsTable::summary($this->submission->load('task', 'media'));

        $links = $hrefs($html);
        expect($links)->toHaveCount(1)->and($html)->toContain('report.pdf');

        $this->actingAs($this->dm)->get($links[0])->assertOk();
        $this->actingAs(adminUser(AdminRole::FinanceOps))->get($links[0])->assertForbidden();
    });

    it('gives the client working links only for shared work', function () use ($hrefs) {
        $page = fn () => Livewire::actingAs($this->owner)->test(DeliverableIndex::class);

        expect($page()->assertDontSee('report.pdf')->html())->not->toContain('/files/');

        ($this->share)();

        $links = $hrefs($page()->assertSee('report.pdf')->html());
        expect($links)->toHaveCount(1);

        $this->actingAs($this->owner)->get($links[0])->assertOk()->assertDownload('report.pdf');
        $this->actingAs(clientUser())->get($links[0])->assertForbidden();
        $this->actingAs($this->me)->get($links[0])->assertOk();
    });
});
