<?php

namespace App\Actions\Files;

use App\Models\Sow;
use App\Models\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Writes one audit entry when a person is given a file (SCR-045): who, which file, when. Never the file contents.
 * Call it only after the policy has allowed the request, so a refused request leaves no entry.
 */
class LogFileDownload
{
    public function media(Media $media, User $actor): void
    {
        activity('file')->causedBy($actor)->performedOn($media)->event('downloaded')
            ->withProperties([
                'media_uuid' => $media->uuid,
                'collection' => $media->collection_name,
                'file_name' => $media->name.'.'.$media->extension,
                'size' => $media->size,
            ])->log('File downloaded');
    }

    /** A SOW handed out as a PDF (`downloaded`) or as a print page (`opened`). */
    public function sow(Sow $sow, User $actor, string $event): void
    {
        $sow->loadMissing('project:id,code');

        activity('file')->causedBy($actor)->performedOn($sow)->event($event)
            ->withProperties([
                'document' => $event === 'downloaded' ? 'sow_pdf' : 'sow_print',
                'project_code' => $sow->project->code,
                'version' => $sow->version,
            ])->log($event === 'downloaded' ? 'File downloaded' : 'SOW opened for print');
    }
}
