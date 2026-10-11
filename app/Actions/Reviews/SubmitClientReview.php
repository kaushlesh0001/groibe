<?php

namespace App\Actions\Reviews;

use App\Actions\Reviews\Concerns\SavesReview;
use App\Enums\FeatureFlag;
use App\Enums\ReviewDirection;
use App\Exceptions\ActionBlocked;
use App\Models\Project;
use App\Models\Review;
use App\Models\User;
use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Support\Facades\Gate;

/**
 * A contributor reviews the client organization after the project is closed (spec 9). Visible to admins only in v1.
 * Off entirely when the `contributor_reviews_of_clients` flag is off.
 */
class SubmitClientReview
{
    use SavesReview;

    /** @param  array<string, mixed>  $ratings  clarity, communication, professionalism, feedback_time, fairness (1 to 5) */
    public function __invoke(Project $project, User $actor, array $ratings, ?string $comment = null): Review
    {
        if (! app(FeatureFlags::class)->enabled(FeatureFlag::ContributorReviewsOfClients)) {
            throw ActionBlocked::guardFailed('Reviewing clients is not available right now.');
        }

        Gate::forUser($actor)->authorize('reviewClient', $project);

        return $this->saveReview($project, $actor, ReviewDirection::ContributorToClient, $project->organization_id, $ratings, $comment);
    }
}
