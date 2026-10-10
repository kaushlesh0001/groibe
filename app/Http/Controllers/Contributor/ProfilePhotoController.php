<?php

namespace App\Http\Controllers\Contributor;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A contributor's own photo, served from the private disk (spec 17.1). */
class ProfilePhotoController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $profile = Profile::query()->where('user_id', $request->user()->id)->firstOrFail();

        abort_if($profile->photo_path === null || ! Storage::disk('local')->exists($profile->photo_path), 404);

        return Storage::disk('local')->response($profile->photo_path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
