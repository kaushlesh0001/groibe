<?php

namespace App\Actions\Reviews;

use App\Actions\Reviews\Concerns\SavesReview;
use App\Enums\ReviewDirection;
use App\Models\Project;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * A client reviews a contributor after the project is accepted (spec 9). Only the client's owner and manager,
 * and only for a contributor whose assignment completed. Starts as `pending_moderation`.
 */
class SubmitContributorReview
{
    use SavesReview;

    /** @param  array<string, mixed>  $ratings  quality, communication, reliability, timeliness (1 to 5) */
    public function __invoke(Project $project, User $actor, User $contributor, array $ratings, ?string $comment = null): Review
    {
        Gate::forUser($actor)->authorize('reviewContributors', $project);

        $this->assertWorkedOn($project, $contributor, completedOnly: true);

        return $this->saveReview($project, $actor, ReviewDirection::ClientToContributor, $contributor->getKey(), $ratings, $comment);
    }
}
