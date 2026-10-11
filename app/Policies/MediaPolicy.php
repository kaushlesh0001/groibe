<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Enums\SubmissionStatus;
use App\Models\Brief;
use App\Models\Deliverable;
use App\Models\Message;
use App\Models\Project;
use App\Models\Submission;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;
use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Private file access (spec 17.2): a file is readable exactly when its owning record is readable,
 * and only for collections the app knows about.
 *
 * Submission files (SCR-013): the contributor who made them, any admin holding `submissions.view`, and
 * a client only for approved work inside a deliverable that has been shared with their organization.
 */
class MediaPolicy
{
    use ChecksOrganizationAccess;

    public function view(User $actor, Media $media): bool
    {
        $media->loadMissing('model');
        $owner = $media->model;

        return match (true) {
            $owner instanceof Brief && $media->collection_name === Brief::FILES => $actor->can('view', $owner),
            $owner instanceof Project && $media->collection_name === Project::FILES => $actor->can('view', $owner),
            $owner instanceof Message && $media->collection_name === Message::FILES => $actor->can('view', $owner),
            $owner instanceof Submission && $media->collection_name === Submission::FILES => $this->canViewSubmissionFile($actor, $owner),
            default => false,
        };
    }

    private function canViewSubmissionFile(User $actor, Submission $submission): bool
    {
        if ($this->adminCan($actor, 'submissions.view')) {
            return true;
        }

        if ($actor->isContributor()) {
            return $submission->user_id === $actor->getKey();
        }

        if (! $actor->isClient() || $submission->status !== SubmissionStatus::QaApproved) {
            return false;
        }

        $organizationIds = $actor->organizations()->pluck('organizations.id');

        return Deliverable::query()
            ->whereNotNull('shared_at')
            ->whereHas('submissions', fn (Builder $submissions) => $submissions->whereKey($submission->getKey()))
            ->whereHas('milestone', fn (Builder $milestone) => $milestone
                ->clientVisible()
                ->whereHas('project', fn (Builder $project) => $project
                    ->where('status', '!=', ProjectStatus::Draft->value)
                    ->whereIn('organization_id', $organizationIds)))
            ->exists();
    }
}
