<?php

namespace App\Actions\Reviews\Concerns;

use App\Enums\ReviewDirection;
use App\Enums\ReviewRevieweeType;
use App\Enums\ReviewStatus;
use App\Exceptions\IllegalTransition;
use App\Jobs\RecomputeReputation;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Moderation mechanics for spec 9: authorize, lock, validate, persist, log, then queue the reputation recompute. */
trait MovesReview
{
    protected function moveReview(Review $review, ReviewStatus $to, User $actor, ?string $note = null): Review
    {
        Gate::forUser($actor)->authorize('moderate', $review);

        DB::transaction(function () use ($review, $to, $actor, $note): void {
            $locked = Review::query()->lockForUpdate()->findOrFail($review->getKey());
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw IllegalTransition::for('review', $from->value, $to->value);
            }

            $attributes = ['status' => $to];

            if ($to === ReviewStatus::Published) {
                $attributes['published_at'] = $locked->published_at ?? now();
            }

            $locked->forceFill($attributes)->save();

            activity('review')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event($to->value)
                ->withProperties(['from' => $from->value, 'to' => $to->value, 'note' => $note])
                ->log("Review moved from {$from->value} to {$to->value}");
        });

        $review->refresh();

        // Internal reviews never feed reputation, so there is nothing to recompute for them.
        if ($review->direction !== ReviewDirection::AdminToContributor) {
            RecomputeReputation::dispatch(
                userId: $review->reviewee_type === ReviewRevieweeType::User ? $review->reviewee_id : null,
                organizationId: $review->reviewee_type === ReviewRevieweeType::Organization ? $review->reviewee_id : null,
            )->afterCommit();
        }

        return $review;
    }
}
