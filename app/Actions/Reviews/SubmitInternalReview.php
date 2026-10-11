<?php

namespace App\Actions\Reviews;

use App\Actions\Reviews\Concerns\SavesReview;
use App\Enums\ProjectStatus;
use App\Enums\ReviewDirection;
use App\Exceptions\ActionBlocked;
use App\Models\Project;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * An admin adds an internal review of a contributor (spec 9). Never shown to the contributor or the public and never
 * counted in reputation (Pass 6 decision). Needs `reviews.create`, which the matrix gives to super admin only.
 */
class SubmitInternalReview
{
    use SavesReview;

    /** @param  array<string, mixed>  $ratings  quality, communication, reliability, timeliness (1 to 5) */
    public function __invoke(Project $project, User $actor, User $contributor, array $ratings, ?string $comment = null): Review
    {
        Gate::forUser($actor)->authorize('create', Review::class);

        if (! in_array($project->status, [ProjectStatus::Accepted, ProjectStatus::Closed], true)) {
            throw ActionBlocked::guardFailed('Internal reviews can be added once the project is accepted.');
        }

        $this->assertWorkedOn($project, $contributor, completedOnly: false);

        return $this->saveReview($project, $actor, ReviewDirection::AdminToContributor, $contributor->getKey(), $ratings, $comment);
    }
}
