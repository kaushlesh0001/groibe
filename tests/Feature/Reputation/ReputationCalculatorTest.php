<?php

use App\Enums\ProjectStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Jobs\RecomputeReputation;
use App\Models\Milestone;
use App\Models\OrganizationStat;
use App\Models\ReputationFact;
use App\Models\Review;
use App\Models\Submission;
use App\Models\Task;
use App\Services\Reputation\ReputationCalculator;
use Illuminate\Support\Facades\Queue;

/**
 * An approved task for the seat. `$firstPass` decides whether version 1 was approved straight away.
 */
function approvedTask(array $seat, bool $firstPass, ?string $due, string $approvedOn): Task
{
    $milestone = Milestone::factory()->create(['project_id' => $seat['project']->id]);
    $task = Task::factory()->create([
        'milestone_id' => $milestone->id,
        'assignment_id' => $seat['assignment']->id,
        'status' => TaskStatus::Approved,
        'due_date' => $due,
        'approved_at' => $approvedOn,
    ]);

    if ($firstPass) {
        Submission::factory()->status(SubmissionStatus::QaApproved)->create(['task_id' => $task->id, 'assignment_id' => $seat['assignment']->id, 'version' => 1]);
    } else {
        Submission::factory()->status(SubmissionStatus::QaChangesRequested)->create(['task_id' => $task->id, 'assignment_id' => $seat['assignment']->id, 'version' => 1]);
        Submission::factory()->status(SubmissionStatus::QaApproved)->create(['task_id' => $task->id, 'assignment_id' => $seat['assignment']->id, 'version' => 2]);
    }

    return $task;
}

it('computes first-pass and on-time shares from approved tasks and leaves tasks without a due date out of the on-time share', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);

    approvedTask($seat, firstPass: true, due: '2026-03-10', approvedOn: '2026-03-09 10:00:00');   // first pass, on time
    approvedTask($seat, firstPass: false, due: '2026-03-10', approvedOn: '2026-03-10 18:00:00');  // reworked, on time (same day)
    approvedTask($seat, firstPass: true, due: '2026-03-10', approvedOn: '2026-03-12 09:00:00');   // first pass, late
    approvedTask($seat, firstPass: true, due: null, approvedOn: '2026-03-12 09:00:00');           // first pass, no due date

    // Not approved, so not counted at all.
    Task::factory()->create([
        'milestone_id' => Milestone::factory()->create(['project_id' => $seat['project']->id])->id,
        'assignment_id' => $seat['assignment']->id,
        'status' => TaskStatus::InProgress,
    ]);

    $facts = app(ReputationCalculator::class)->forUser($seat['contributor']);

    expect($facts->tasks_total)->toBe(4)
        ->and($facts->tasks_approved_first_pass)->toBe(3)
        ->and($facts->firstPassPercent())->toBe(75)
        ->and($facts->tasks_with_due_date)->toBe(3)
        ->and($facts->on_time_tasks)->toBe(2)
        ->and($facts->onTimePercent())->toBe(67)
        ->and($facts->projects_completed)->toBe(1);
});

it('reports no percentages instead of dividing by zero', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);

    $facts = app(ReputationCalculator::class)->forUser($seat['contributor']);

    expect($facts->firstPassPercent())->toBeNull()
        ->and($facts->onTimePercent())->toBeNull()
        ->and($facts->avg_rating)->toBeNull()
        ->and($facts->reviews_count)->toBe(0);
});

it('averages only published client reviews and ignores pending, hidden, internal and organization reviews', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);
    $contributor = $seat['contributor'];

    // Two published client reviews: 4.5 and 3.0 average to 3.75.
    Review::factory()->published()->create(['project_id' => $seat['project']->id, 'reviewee_id' => $contributor->id, 'quality' => 5, 'communication' => 4, 'reliability' => 5, 'timeliness' => 4]);
    Review::factory()->published()->create(['project_id' => $seat['project']->id, 'reviewee_id' => $contributor->id, 'quality' => 3, 'communication' => 3, 'reliability' => 3, 'timeliness' => 3]);
    // Never counted.
    Review::factory()->create(['project_id' => $seat['project']->id, 'reviewee_id' => $contributor->id, 'quality' => 1, 'communication' => 1, 'reliability' => 1, 'timeliness' => 1]);
    Review::factory()->published()->create(['project_id' => $seat['project']->id, 'reviewee_id' => $contributor->id, 'quality' => 1, 'communication' => 1, 'reliability' => 1, 'timeliness' => 1, 'status' => 'hidden']);
    Review::factory()->published()->internal()->create(['project_id' => $seat['project']->id, 'reviewee_id' => $contributor->id, 'quality' => 1, 'communication' => 1, 'reliability' => 1, 'timeliness' => 1]);
    Review::factory()->published()->ofClient($seat['organization'])->create(['project_id' => $seat['project']->id, 'reviewer_id' => $contributor->id]);

    $facts = app(ReputationCalculator::class)->forUser($contributor);

    expect($facts->reviews_count)->toBe(2)
        ->and((float) $facts->avg_rating)->toBe(3.75);
});

it('counts verified skills', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);
    $seat['contributor']->skills()->updateExistingPivot($seat['contributor']->skills()->first()->id, ['is_verified' => true]);

    expect(app(ReputationCalculator::class)->forUser($seat['contributor'])->verified_skills_count)->toBe(1);
});

it('computes organization averages from published contributor reviews only', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);

    Review::factory()->published()->ofClient($seat['organization'])->create(['project_id' => $seat['project']->id, 'reviewer_id' => $seat['contributor']->id]);
    Review::factory()->ofClient($seat['organization'])->create(['project_id' => $seat['project']->id]); // pending, ignored

    $stats = app(ReputationCalculator::class)->forOrganization($seat['organization']);

    expect($stats->reviews_count)->toBe(1)
        ->and((float) $stats->avg_clarity)->toBe(4.0)
        ->and((float) $stats->avg_fairness)->toBe(5.0)
        ->and((float) $stats->avg_rating)->toBe(4.2)
        ->and($stats->projects_completed)->toBe(1);
});

it('is idempotent: recomputing twice keeps one row', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);

    app(ReputationCalculator::class)->forUser($seat['contributor']);
    app(ReputationCalculator::class)->forUser($seat['contributor']);

    expect(ReputationFact::query()->where('user_id', $seat['contributor']->id)->count())->toBe(1);
});

it('recomputes through the queued job and tolerates a missing record', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);

    (new RecomputeReputation(userId: $seat['contributor']->id, organizationId: $seat['organization']->id))->handle(app(ReputationCalculator::class));
    (new RecomputeReputation(userId: 999999, organizationId: 999999))->handle(app(ReputationCalculator::class));

    expect(ReputationFact::query()->count())->toBe(1)
        ->and(OrganizationStat::query()->count())->toBe(1);

    Queue::fake();
    RecomputeReputation::dispatch(userId: 1);
    Queue::assertPushed(RecomputeReputation::class);
});
