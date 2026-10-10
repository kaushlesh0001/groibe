<?php

namespace App\Policies;

use App\Models\Brief;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Private file access (spec 17.2): a file is readable exactly when its owning record is readable,
 * and only for collections this pass knows about. Later passes add submission files.
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
            default => false,
        };
    }
}
