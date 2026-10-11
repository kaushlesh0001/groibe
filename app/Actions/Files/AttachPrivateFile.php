<?php

namespace App\Actions\Files;

use App\Models\Brief;
use App\Models\Message;
use App\Models\Project;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Stores an uploaded document in a private collection (spec 17.2): allowlisted type, size cap,
 * randomized storage name, original name kept only as display text.
 */
class AttachPrivateFile
{
    public function __invoke(Brief|Project $model, UploadedFile $file, User $actor): Media
    {
        Gate::forUser($actor)->authorize('update', $model);

        $collection = $model instanceof Brief ? Brief::FILES : Project::FILES;

        return $this->store($model, $file, $actor, $collection, (int) config('groibe.uploads.document_max_kb'));
    }

    /**
     * Validates and stores one file with no authorization of its own: the calling Action has already
     * decided that the actor may add to this record.
     *
     * @throws ValidationException
     */
    public function store(Brief|Message|Project|Submission $model, UploadedFile $file, User $actor, string $collection, int $maxKb): Media
    {
        $this->validate($file, $maxKb);

        $extension = Str::lower($file->getClientOriginalExtension());
        $displayName = Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 120, '');

        $media = $model->addMedia($file)
            ->usingName($displayName)
            ->usingFileName(Str::lower((string) Str::ulid()).'.'.$extension)
            ->withCustomProperties(['uploaded_by' => $actor->getKey()])
            ->toMediaCollection($collection);

        activity('file')->causedBy($actor)->performedOn($model)->event('uploaded')
            ->withProperties(['media_uuid' => $media->uuid, 'collection' => $collection])->log('File uploaded');

        return $media;
    }

    /** @throws ValidationException */
    public function validate(UploadedFile $file, int $maxKb): void
    {
        $extensions = config('groibe.uploads.document_extensions');

        Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'max:'.$maxKb, 'extensions:'.implode(',', $extensions), 'mimes:'.implode(',', $extensions)],
        ])->validate();
    }
}
