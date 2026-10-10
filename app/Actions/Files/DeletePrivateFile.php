<?php

namespace App\Actions\Files;

use App\Models\Brief;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Removes a file from a record the actor may still edit. The file must belong to that record. */
class DeletePrivateFile
{
    public function __invoke(Brief|Project $model, Media $media, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $model);

        abort_unless($media->model_type === $model->getMorphClass() && (int) $media->model_id === $model->getKey(), 404);

        activity('file')->causedBy($actor)->performedOn($model)->event('deleted')
            ->withProperties(['media_uuid' => $media->uuid])->log('File deleted');

        $media->delete();
    }
}
