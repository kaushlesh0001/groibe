<?php

use App\Actions\Files\AttachPrivateFile;
use App\Actions\Messages\GetProjectThread;
use App\Actions\Sows\CreateSow;
use App\Actions\Sows\SendSow;
use App\Enums\AdminRole;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\ProjectStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\RelationManagers\SowsRelationManager;
use App\Models\Assignment;
use App\Models\Brief;
use App\Models\Message;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Submission;
use App\Models\Task;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/*
 * SCR-045: every time a person is given a file, the audit log records who, which file and when, and never the contents.
 * A refused request records nothing.
 */
beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    publishAgreements();

    $this->org = Organization::factory()->verified()->create();
    $this->owner = acceptClientMsa($this->org);
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['organization_id' => $this->org->id, 'delivery_manager_id' => $this->dm->id]);
    $this->me = contributorIn(OnboardingState::Active);
    $this->seat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->me->id]);

    $this->secret = 'CONFIDENTIAL-CONTENTS-4471';
    $this->pdf = fn (string $name) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n{$this->secret}\n%%EOF\n");

    $brief = Brief::factory()->create(['organization_id' => $this->org->id]);
    $milestone = Milestone::factory()->status(MilestoneStatus::InQa)->create(['project_id' => $this->project->id]);
    $task = Task::factory()->status(TaskStatus::Submitted)->create(['milestone_id' => $milestone->id, 'assignment_id' => $this->seat->id]);
    $submission = Submission::factory()->status(SubmissionStatus::Submitted)->create(['task_id' => $task->id, 'assignment_id' => $this->seat->id, 'user_id' => $this->me->id]);
    $thread = app(GetProjectThread::class)->ensure($this->project, $this->dm);
    $message = Message::factory()->create(['thread_id' => $thread->id, 'user_id' => $this->owner->id]);

    $store = fn ($model, string $collection, string $name) => app(AttachPrivateFile::class)->store($model, ($this->pdf)($name), $this->owner, $collection, 20480);

    // [media, a person who may open it, a person who may not]
    $this->files = [
        'brief file' => [$store($brief, Brief::FILES, 'brief.pdf'), fn () => $this->owner, fn () => adminUser(AdminRole::FinanceOps)],
        'project file' => [$store($this->project, Project::FILES, 'plan.pdf'), fn () => $this->owner, fn () => contributorIn(OnboardingState::Active)],
        'submission file' => [$store($submission, Submission::FILES, 'work.pdf'), fn () => $this->me, fn () => $this->owner],
        'message file' => [$store($message, Message::FILES, 'note.pdf'), fn () => $this->owner, fn () => contributorIn(OnboardingState::Active)],
    ];

    $this->link = fn (Media $media) => URL::temporarySignedRoute('files.show', now()->addMinutes(5), ['media' => $media->uuid]);
    $this->served = fn () => Activity::query()->where('log_name', 'file')->whereIn('event', ['downloaded', 'opened'])->get();
    $this->downloads = fn () => Activity::query()->where('log_name', 'file')->where('event', 'downloaded')->get();
});

describe('the file route (briefs, projects, submissions, messages)', function () {
    it('writes one entry for each download: who, which file, when', function (string $label) {
        [$media, $allowed] = $this->files[$label];
        $actor = $allowed();

        $this->actingAs($actor)->get(($this->link)($media))->assertOk();

        $entry = ($this->downloads)()->sole();

        expect($entry->causer_id)->toBe($actor->getKey())
            ->and($entry->subject_id)->toBe($media->getKey())
            ->and($entry->description)->toBe('File downloaded')
            ->and($entry->properties['media_uuid'])->toBe($media->uuid)
            ->and($entry->properties['collection'])->toBe($media->collection_name)
            ->and($entry->created_at)->not->toBeNull();
    })->with(['brief file', 'project file', 'submission file', 'message file']);

    it('never writes the file contents or its stored path', function (string $label) {
        [$media, $allowed] = $this->files[$label];

        $this->actingAs($allowed())->get(($this->link)($media))->assertOk();

        $stored = json_encode(($this->downloads)()->sole()->toArray());

        expect($stored)->not->toContain($this->secret)->not->toContain($media->file_name)->not->toContain($media->getPathRelativeToRoot());
    })->with(['brief file', 'project file', 'submission file', 'message file']);

    it('writes one more entry for each further download, not one for the whole session', function () {
        [$media, $allowed] = $this->files['brief file'];
        $actor = $allowed();

        $this->actingAs($actor)->get(($this->link)($media))->assertOk();
        $this->actingAs($actor)->get(($this->link)($media))->assertOk();

        expect(($this->downloads)())->toHaveCount(2);
    });

    it('writes nothing when access is denied', function (string $label) {
        [$media, , $denied] = $this->files[$label];

        $this->actingAs($denied())->get(($this->link)($media))->assertForbidden();

        expect(($this->downloads)())->toHaveCount(0);
    })->with(['brief file', 'project file', 'submission file', 'message file']);

    it('writes nothing for a guest, a bad signature, an expired link, a guessed id or a file that is gone', function () {
        [$media, $allowed] = $this->files['brief file'];
        $actor = $allowed();
        $signed = ($this->link)($media);

        $this->get($signed)->assertRedirect('/login');
        $this->actingAs($actor)->get(route('files.show', ['media' => $media->uuid]))->assertForbidden();
        $this->actingAs($actor)->get($signed.'x')->assertForbidden();
        $this->actingAs($actor)->get(URL::temporarySignedRoute('files.show', now()->subMinute(), ['media' => $media->uuid]))->assertForbidden();
        $this->actingAs($actor)->get('/files/'.$media->id)->assertNotFound();

        Storage::disk('local')->delete($media->getPathRelativeToRoot());
        $this->actingAs($actor)->get($signed)->assertNotFound();

        expect(($this->downloads)())->toHaveCount(0);
    });
});

describe('SOW documents', function () {
    beforeEach(function () {
        // A project still at the proposal stage: the SOW can only be created before the project is staffed.
        $this->sowProject = Project::factory()->create(['organization_id' => $this->org->id]);
        $this->sow = app(SendSow::class)(app(CreateSow::class)($this->sowProject, $this->dm), $this->dm);
        $this->base = "/app/client/projects/{$this->sowProject->uuid}/sows/{$this->sow->version}";
        $this->other = acceptClientMsa(Organization::factory()->verified()->create());
    });

    it('logs the PDF download of the client who took it', function () {
        $this->actingAs($this->owner)->get("{$this->base}/pdf")->assertOk();

        $entry = ($this->downloads)()->sole();

        expect($entry->causer_id)->toBe($this->owner->getKey())->and($entry->subject_id)->toBe($this->sow->getKey())
            ->and($entry->properties['document'])->toBe('sow_pdf')->and($entry->properties['project_code'])->toBe($this->sowProject->code);
    });

    it('logs the print page as opened, not downloaded', function () {
        $this->actingAs($this->owner)->get("{$this->base}/print")->assertOk();

        $entry = ($this->served)()->sole();

        expect($entry->event)->toBe('opened')->and($entry->causer_id)->toBe($this->owner->getKey())->and($entry->properties['document'])->toBe('sow_print')
            ->and(($this->downloads)())->toHaveCount(0);
    });

    it('logs nothing for another organization, a contributor or a guest', function () {
        $this->actingAs($this->other)->get("{$this->base}/pdf")->assertForbidden();
        $this->actingAs($this->other)->get("{$this->base}/print")->assertForbidden();
        $this->actingAs($this->me)->get("{$this->base}/pdf")->assertForbidden();
        auth()->forgetGuards();
        $this->get("{$this->base}/pdf")->assertRedirect('/login');

        expect(($this->served)())->toHaveCount(0);
    });

    it('logs the admin PDF action, which only people who may read the SOW are shown', function () {
        $manager = Livewire::actingAs($this->dm)->test(SowsRelationManager::class, ['ownerRecord' => $this->sowProject, 'pageClass' => EditProject::class]);
        $manager->callAction(TestAction::make('pdf')->table($this->sow))->assertFileDownloaded($this->sowProject->code.'-SOW-v1.pdf');

        $entry = ($this->downloads)()->sole();

        expect($entry->causer_id)->toBe($this->dm->getKey())->and($entry->properties['document'])->toBe('sow_pdf');

        // The action is shown only to people who may view the SOW; a community manager may not, so nothing is ever logged for them.
        expect(adminUser(AdminRole::CommunityManager)->can('view', $this->sow))->toBeFalse();
        expect(($this->downloads)())->toHaveCount(1);
    });
});
