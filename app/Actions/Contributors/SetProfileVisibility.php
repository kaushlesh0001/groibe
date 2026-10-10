<?php

namespace App\Actions\Contributors;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Public toggle (spec 11). The slug is created on the first publish and then never changes (spec 16). */
class SetProfileVisibility
{
    public function __construct(private GenerateProfileSlug $slugs) {}

    public function __invoke(Profile $profile, bool $public, User $actor): Profile
    {
        Gate::forUser($actor)->authorize('update', $profile);

        $profile->forceFill([
            'is_public' => $public,
            'slug' => $public ? ($this->slugs)($profile) : $profile->slug,
        ])->save();

        activity('contributor')->causedBy($actor)->performedOn($profile)->event('visibility_changed')
            ->withProperties(['is_public' => $public])->log($public ? 'Profile made public' : 'Profile made private');

        return $profile;
    }
}
