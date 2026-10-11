<?php

namespace App\Actions\Reviews\Concerns;

use App\Enums\AssignmentStatus;
use App\Enums\ReviewDirection;
use App\Enums\ReviewStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\Review;
use App\Models\User;
use App\Notifications\ReviewSubmitted;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Shared mechanics for writing a review (spec 9): validate ratings, enforce one review per reviewer and reviewee, log, tell moderators. */
trait SavesReview
{
    public function __construct(private NotificationDispatcher $dispatcher) {}

    /**
     * @param  array<string, mixed>  $ratings  criterion => 1..5, every criterion of the direction required
     */
    protected function saveReview(
        Project $project,
        User $reviewer,
        ReviewDirection $direction,
        int $revieweeId,
        array $ratings,
        ?string $comment,
    ): Review {
        $values = [];

        foreach ($direction->criteria() as $criterion) {
            $value = $ratings[$criterion] ?? null;

            if (! is_numeric($value) || (int) $value != $value || (int) $value < 1 || (int) $value > 5) {
                throw ActionBlocked::guardFailed('Please rate every criterion from 1 to 5.');
            }

            $values[$criterion] = (int) $value;
        }

        $attributes = [
            'project_id' => $project->getKey(),
            'reviewer_id' => $reviewer->getKey(),
            'reviewee_type' => $direction->revieweeType(),
            'reviewee_id' => $revieweeId,
        ];

        try {
            $review = DB::transaction(function () use ($attributes, $direction, $values, $comment, $reviewer): Review {
                if (Review::query()->where($this->uniqueKey($attributes))->lockForUpdate()->exists()) {
                    throw ActionBlocked::guardFailed('You have already reviewed this. Reviews cannot be changed once sent.');
                }

                $review = Review::query()->create([
                    ...$attributes,
                    ...$values,
                    'direction' => $direction,
                    'comment' => filled($comment) ? trim((string) $comment) : null,
                    'status' => ReviewStatus::PendingModeration,
                ]);

                activity('review')
                    ->causedBy($reviewer)
                    ->performedOn($review)
                    ->event('submitted')
                    ->withProperties(['direction' => $direction->value, 'project_id' => $attributes['project_id']])
                    ->log('Review submitted for moderation');

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            throw ActionBlocked::guardFailed('You have already reviewed this. Reviews cannot be changed once sent.');
        }

        $this->dispatcher->toAdmins('reviews.approve', new ReviewSubmitted($review));

        return $review;
    }

    /** The contributor held a seat on the project that finished or was removed, so they can be reviewed. */
    protected function assertWorkedOn(Project $project, User $contributor, bool $completedOnly): void
    {
        $query = Assignment::query()->where('project_id', $project->getKey())->where('user_id', $contributor->getKey());

        if ($completedOnly) {
            $query->where('status', AssignmentStatus::Completed->value);
        } else {
            $query->whereIn('status', [AssignmentStatus::Active->value, AssignmentStatus::Completed->value, AssignmentStatus::Removed->value]);
        }

        if (! $query->exists()) {
            throw ActionBlocked::guardFailed('That contributor did not complete work on this project.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function uniqueKey(array $attributes): array
    {
        return [...$attributes, 'reviewee_type' => $attributes['reviewee_type']->value];
    }
}
