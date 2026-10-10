<?php

use App\Enums\AssignmentStatus;
use App\Enums\MilestoneStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Models\Assignment;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Database\QueryException;

/** Every legal edge from the spec, as [from, to] pairs. Anything else must be refused. */
dataset('state machines', [
    'assignment 6.6' => [AssignmentStatus::class, [
        ['invited', 'active'], ['invited', 'declined'], ['active', 'completed'], ['active', 'removed'],
    ]],
    'task 6.7' => [TaskStatus::class, [
        ['todo', 'in_progress'], ['in_progress', 'submitted'], ['submitted', 'approved'],
        ['submitted', 'changes_requested'], ['changes_requested', 'in_progress'],
    ]],
    'submission 6.8' => [SubmissionStatus::class, [
        ['submitted', 'qa_changes_requested'], ['submitted', 'qa_approved'],
    ]],
    'milestone 6.9' => [MilestoneStatus::class, [
        ['planned', 'in_progress'], ['in_progress', 'in_qa'], ['in_qa', 'shared'],
        ['shared', 'client_accepted'], ['shared', 'client_changes_requested'], ['client_changes_requested', 'in_progress'],
    ]],
]);

it('allows exactly the transitions in the spec', function (string $enum, array $legal) {
    $legalKeys = array_map(fn (array $edge) => $edge[0].'>'.$edge[1], $legal);

    foreach ($enum::cases() as $from) {
        foreach ($enum::cases() as $to) {
            $expected = in_array($from->value.'>'.$to->value, $legalKeys, true);

            expect($from->canTransitionTo($to))->toBe($expected, "{$from->value} -> {$to->value}");
        }
    }
})->with('state machines');

it('treats only invited and active assignments as open seats', function () {
    $project = Project::factory()->create();

    foreach (AssignmentStatus::cases() as $status) {
        Assignment::factory()->status($status)->create(['project_id' => $project->id]);
    }

    expect(Assignment::query()->open()->pluck('status')->map->value->sort()->values()->all())
        ->toBe(['active', 'invited'])
        ->and(Assignment::query()->active()->count())->toBe(1);
});

it('shows milestones to clients only from shared onwards', function () {
    $project = Project::factory()->create();

    foreach (MilestoneStatus::cases() as $status) {
        Milestone::factory()->status($status)->create(['project_id' => $project->id]);
    }

    $visible = Milestone::query()->clientVisible()->pluck('status')->map->value->sort()->values()->all();

    expect($visible)->toBe(['client_accepted', 'client_changes_requested', 'shared']);

    foreach (MilestoneStatus::cases() as $status) {
        expect($status->isClientVisible())->toBe(in_array($status->value, $visible, true));
    }
});

it('links the work engine records through the project', function () {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    $assignment = Assignment::factory()->active()->create(['project_id' => $project->id]);
    $task = Task::factory()->create(['milestone_id' => $milestone->id, 'assignment_id' => $assignment->id]);
    $submission = Submission::factory()->create(['task_id' => $task->id, 'assignment_id' => $assignment->id]);
    $deliverable = Deliverable::factory()->create(['milestone_id' => $milestone->id]);
    $deliverable->submissions()->attach($submission);

    expect($task->project_id)->toBe($project->id)
        ->and($submission->user_id)->toBe($assignment->user_id)
        ->and($project->assignments()->count())->toBe(1)
        ->and($project->milestones()->count())->toBe(1)
        ->and($project->tasks()->count())->toBe(1)
        ->and($deliverable->submissions->first()->is($submission))->toBeTrue()
        ->and($submission->deliverables->first()->is($deliverable))->toBeTrue();
});

it('hides internal task comments from the client scope', function () {
    $task = Task::factory()->create();
    TaskComment::factory()->create(['task_id' => $task->id, 'body' => 'public']);
    TaskComment::factory()->internal()->create(['task_id' => $task->id, 'body' => 'internal']);

    expect($task->comments()->visibleToClient()->pluck('body')->all())->toBe(['public']);
});

it('finds overdue tasks and ignores approved or undated ones', function () {
    $milestone = Milestone::factory()->create();

    $overdue = Task::factory()->create(['milestone_id' => $milestone->id, 'due_date' => now()->subDay()]);
    Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $milestone->id, 'due_date' => now()->subDay()]);
    Task::factory()->create(['milestone_id' => $milestone->id, 'due_date' => now()->addDay()]);
    Task::factory()->create(['milestone_id' => $milestone->id]);

    expect(Task::query()->overdue()->pluck('id')->all())->toBe([$overdue->id]);
});

it('keeps one submission per task version and stores files privately', function () {
    $task = Task::factory()->create();
    $assignment = Assignment::factory()->active()->create(['project_id' => $task->project_id]);

    Submission::factory()->create(['task_id' => $task->id, 'assignment_id' => $assignment->id, 'version' => 1]);

    expect(fn () => Submission::factory()->create(['task_id' => $task->id, 'assignment_id' => $assignment->id, 'version' => 1]))
        ->toThrow(QueryException::class);

    $collection = (new Submission)->getRegisteredMediaCollections()->firstWhere('name', Submission::FILES);

    expect(Submission::FILES)->toBe('submission_files')
        ->and($collection->diskName)->toBe('local');
});

it('defaults hide_team to false on projects', function () {
    expect(Project::factory()->create()->fresh()->hide_team)->toBeFalse()
        ->and(Project::factory()->create(['hide_team' => true])->fresh()->hide_team)->toBeTrue();
});
