<?php

namespace App\Actions\Reviews;

use App\Actions\Reviews\Concerns\MovesReview;
use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Models\User;

/** pending_moderation | published -> hidden (spec 9). Queues a reputation recompute. */
class HideReview
{
    use MovesReview;

    public function __invoke(Review $review, User $actor, ?string $note = null): Review
    {
        return $this->moveReview($review, ReviewStatus::Hidden, $actor, $note);
    }
}
