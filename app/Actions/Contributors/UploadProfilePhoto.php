<?php

namespace App\Actions\Contributors;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores a profile photo on the private disk (spec 17.1, 17.2): allowlisted image types, 2 MB cap,
 * re-encoded to JPEG with a size limit so metadata and embedded payloads are dropped, random name.
 * Public viewing goes through the directory photo route, which checks visibility on every request.
 */
class UploadProfilePhoto
{
    public const DIRECTORY = 'profile-photos';

    public function __invoke(Profile $profile, UploadedFile $file, User $actor): Profile
    {
        Gate::forUser($actor)->authorize('update', $profile);

        $extensions = implode(',', config('groibe.uploads.photo_extensions'));

        Validator::make(['photo' => $file], [
            'photo' => ['required', 'image', 'max:'.config('groibe.uploads.photo_max_kb'), 'extensions:'.$extensions, 'mimes:'.$extensions],
        ])->validate();

        $this->assertWithinPixelLimit($file);

        $contents = $this->reencode($file);
        $path = self::DIRECTORY.'/'.Str::lower((string) Str::ulid()).'.jpg';

        Storage::disk('local')->put($path, $contents);

        $old = $profile->photo_path;
        $profile->forceFill(['photo_path' => $path])->save();

        if ($old !== null) {
            Storage::disk('local')->delete($old);
        }

        activity('contributor')->causedBy($actor)->performedOn($profile)->event('photo_uploaded')->log('Profile photo uploaded');

        return $profile;
    }

    public function remove(Profile $profile, User $actor): Profile
    {
        Gate::forUser($actor)->authorize('update', $profile);

        if ($profile->photo_path !== null) {
            Storage::disk('local')->delete($profile->photo_path);
            $profile->forceFill(['photo_path' => null])->save();

            activity('contributor')->causedBy($actor)->performedOn($profile)->event('photo_removed')->log('Profile photo removed');
        }

        return $profile;
    }

    /**
     * The file-size cap does not bound the memory a decode needs: a 2 MB PNG can be 15000 by 15000 pixels and take about 900 MB.
     * The size is read from the image header, without decoding, and refused above the limit (spec 17.1, under 256 MB a request).
     */
    private function assertWithinPixelLimit(UploadedFile $file): void
    {
        $size = @getimagesize((string) $file->getRealPath());

        if ($size === false || (int) config('groibe.uploads.photo_max_pixels') < $size[0] * $size[1]) {
            throw ValidationException::withMessages(['photo' => 'That image is too large in pixels. Resize it to under 5000 by 5000 pixels and try again.']);
        }
    }

    private function reencode(UploadedFile $file): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw ValidationException::withMessages(['photo' => 'That image could not be read. Please upload a different file.']);
        }

        $max = (int) config('groibe.uploads.photo_max_px');
        $width = imagesx($source);
        $height = imagesy($source);

        if (max($width, $height) > $max) {
            $scaled = $width >= $height
                ? imagescale($source, $max, (int) round($height * $max / $width))
                : imagescale($source, (int) round($width * $max / $height), $max);
            $source = $scaled !== false ? $scaled : $source;
        }

        // Flatten transparency onto white so PNG and WebP convert cleanly to JPEG.
        $canvas = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));

        ob_start();
        imagejpeg($canvas, null, 85);

        return (string) ob_get_clean();
    }
}
