<?php

namespace App\Actions\Contributors;

use App\Models\Profile;
use Illuminate\Support\Str;

/** Builds a unique public slug from the name plus a random suffix (spec 16). Never regenerated once set. */
class GenerateProfileSlug
{
    public function __invoke(Profile $profile): string
    {
        if ($profile->slug !== null) {
            return $profile->slug;
        }

        $base = Str::limit(Str::slug($profile->user()->firstOrFail()->name) ?: 'contributor', 40, '');

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Profile::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
