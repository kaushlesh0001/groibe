<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to read a private file (spec 17.2): signed URL, signed-in user, and the owning record's policy.
 * Files live on the `local` disk, which is never web-served.
 */
class MediaDownloadController extends Controller
{
    public function __invoke(Media $media): StreamedResponse
    {
        Gate::authorize('view', $media);

        $downloadName = $media->name.'.'.$media->extension;

        return Storage::disk($media->disk)->download($media->getPathRelativeToRoot(), $downloadName, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
