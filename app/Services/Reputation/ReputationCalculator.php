<?php

namespace App\Services\Reputation;

use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReviewDirection;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\OrganizationStat;
use App\Models\Project;
use App\Models\ReputationFact;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Evidence counts and averages for spec 9. There is no composite score. Results are cached in `reputation_facts`
 * and `organization_stats` and recomputed by a queued job on project close and on review publish or hide.
 *
 * Pass 6 definitions:
 * - projects completed: distinct projects where the contributor's assignment is `completed`.
 * - tasks total: tasks assigned to the contributor that QA approved.
 * - first pass: approved tasks whose version 1 submission was approved without a change request.
 * - on time: approved tasks with a due date that were approved on or before it. Tasks without a due date are
 *   left out of the on-time share, so the share has its own denominator (`tasks_with_due_date`).
 * - average rating: mean of the per-review mean of the four contributor criteria, over published client reviews.
 *   Internal admin reviews and unpublished reviews never count.
 */
class ReputationCalculator
{
    public function forUser(User $user): ReputationFact
    {
        $assignmentIds = Assignment::query()->where('user_id', $user->getKey())->pluck('id');

        $tasks = Task::query()
            ->whereIn('assignment_id', $assignmentIds)
            ->where('status', TaskStatus::Approved->value)
            ->withExists(['submissions as first_pass' => fn ($q) => $q
                ->where('version', 1)
                ->where('status', SubmissionStatus::QaApproved->value)])
            ->get(['id', 'due_date', 'approved_at']);

        $withDueDate = $tasks->filter(fn (Task $task): bool => $task->due_date !== null && $task->approved_at !== null);

        $ratings = $this->publishedReviews(ReviewDirection::ClientToContributor)
            ->aboutUser($user)
            ->get()
            ->map(fn (Review $review): ?float => $review->rating())
            ->filter(fn (?float $rating): bool => $rating !== null);

        return ReputationFact::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'projects_completed' => Assignment::query()
                    ->where('user_id', $user->getKey())
                    ->where('status', AssignmentStatus::Completed->value)
                    ->distinct()
                    ->count('project_id'),
                'tasks_total' => $tasks->count(),
                'tasks_approved_first_pass' => $tasks->where('first_pass', true)->count(),
                'tasks_with_due_date' => $withDueDate->count(),
                'on_time_tasks' => $withDueDate
                    ->filter(fn (Task $task): bool => $task->approved_at->toDateString() <= $task->due_date->toDateString())
                    ->count(),
                'reviews_count' => $ratings->count(),
                'avg_rating' => $this->mean($ratings),
                'verified_skills_count' => $user->skills()->wherePivot('is_verified', true)->count(),
                'recomputed_at' => now(),
            ],
        );
    }

    public function forOrganization(Organization $organization): OrganizationStat
    {
        $reviews = $this->publishedReviews(ReviewDirection::ContributorToClient)
            ->aboutOrganization($organization)
            ->get();

        $perReview = $reviews
            ->map(fn (Review $review): ?float => $review->rating())
            ->filter(fn (?float $rating): bool => $rating !== null);

        return OrganizationStat::query()->updateOrCreate(
            ['organization_id' => $organization->getKey()],
            [
                'projects_completed' => Project::query()
                    ->forOrganization($organization)
                    ->whereIn('status', [ProjectStatus::Accepted->value, ProjectStatus::Closed->value])
                    ->count(),
                'reviews_count' => $perReview->count(),
                'avg_rating' => $this->mean($perReview),
                'avg_clarity' => $this->mean($reviews->pluck('clarity')),
                'avg_communication' => $this->mean($reviews->pluck('communication')),
                'avg_professionalism' => $this->mean($reviews->pluck('professionalism')),
                'avg_feedback_time' => $this->mean($reviews->pluck('feedback_time')),
                'avg_fairness' => $this->mean($reviews->pluck('fairness')),
                'recomputed_at' => now(),
            ],
        );
    }

    /** @return Builder<Review> */
    private function publishedReviews(ReviewDirection $direction)
    {
        return Review::query()->published()->where('reviews.direction', $direction->value);
    }

    /** Mean rounded to two places, ignoring nulls; null when nothing was rated. @param Collection<int, mixed> $values */
    private function mean(Collection $values): ?float
    {
        $values = $values->filter(fn ($value): bool => $value !== null);

        return $values->isEmpty() ? null : round((float) $values->avg(), 2);
    }
}
