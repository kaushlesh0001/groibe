<?php

use App\Enums\OnboardingState;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Livewire\Contributor\Work\TaskDetail;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Submission;
use App\Models\Task;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Spec 17.2 caps submissions at 25 MB. The Livewire temporary upload step runs first and has its own rule
 * (config/livewire.php), so it must not be tighter than the largest per-field cap or files between the two
 * would be refused before the submission rules ever see them.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->me = contributorIn(OnboardingState::Active);
    $project = Project::factory()->status(ProjectStatus::Active)->create();
    $seat = Assignment::factory()->active()->create(['project_id' => $project->id, 'user_id' => $this->me->id]);
    $this->task = Task::factory()->status(TaskStatus::InProgress)->create(['milestone_id' => Milestone::factory()->create(['project_id' => $project->id])->id, 'assignment_id' => $seat->id]);
});

it('lets a contributor upload a file between 20 and 25 MB through the real upload step', function () {
    $page = Livewire::actingAs($this->me)->test(TaskDetail::class, ['task' => $this->task])
        ->set('uploads', [UploadedFile::fake()->create('large-model.pdf', 24 * 1024, 'application/pdf')])
        ->assertHasNoErrors()
        ->call('submit')
        ->assertHasNoErrors();

    expect(Submission::query()->sole()->getMedia(Submission::FILES))->toHaveCount(1);
});

it('still refuses a file over 25 MB', function () {
    Livewire::actingAs($this->me)->test(TaskDetail::class, ['task' => $this->task])
        ->set('uploads', [UploadedFile::fake()->create('too-large.pdf', 26 * 1024, 'application/pdf')])
        ->call('submit')
        ->assertHasErrors();

    expect(Submission::query()->count())->toBe(0);
});
