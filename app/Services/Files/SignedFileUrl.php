<?php

namespace App\Services\Files;

use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Short-lived signed link to a private file (spec 17.2). The link only proves the URL was issued by
 * this app; the download route still requires a signed-in user and the owning record's policy.
 */
class SignedFileUrl
{
    public function for(Media $media): string
    {
        return URL::temporarySignedRoute(
            'files.show',
            now()->addMinutes((int) config('groibe.uploads.signed_url_minutes')),
            ['media' => $media->uuid],
        );
    }

    /**
     * Display data for a list of files: name with extension, size and a fresh signed link each.
     *
     * @param  iterable<Media>  $files
     * @return list<array{name: string, size: string, url: string}>
     */
    public function describe(iterable $files): array
    {
        $out = [];

        foreach ($files as $media) {
            $out[] = [
                'name' => $media->name.'.'.$media->extension,
                'size' => $media->human_readable_size,
                'url' => $this->for($media),
            ];
        }

        return $out;
    }
}
