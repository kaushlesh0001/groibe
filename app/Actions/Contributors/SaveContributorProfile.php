<?php

namespace App\Actions\Contributors;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Saves the editable profile fields. Callers validate first (Livewire form object). Visibility and photo have their own Actions. */
class SaveContributorProfile
{
    private const FIELDS = [
        'headline', 'bio', 'phone', 'city', 'state', 'country', 'linkedin_url', 'portfolio_url',
        'availability', 'hours_per_week', 'work_modes', 'experience_years', 'languages',
    ];

    /** @param  array<string, mixed>  $data */
    public function __invoke(Profile $profile, array $data, User $actor): Profile
    {
        Gate::forUser($actor)->authorize('update', $profile);

        $fields = array_intersect_key($data, array_flip(self::FIELDS));

        $profile->fill($fields)->save();

        activity('contributor')->causedBy($actor)->performedOn($profile)->event('profile_saved')
            ->withProperties(['fields' => array_keys($fields)])
            ->log('Profile saved');

        return $profile;
    }
}
