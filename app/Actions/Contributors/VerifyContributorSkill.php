<?php

namespace App\Actions\Contributors;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Admin marks one of a contributor's skills as verified, or clears it (spec 7.2, 13). */
class VerifyContributorSkill
{
    public function __invoke(Profile $profile, int $skillId, User $actor, bool $verified = true): void
    {
        Gate::forUser($actor)->authorize('verifySkills', $profile);

        $user = $profile->user()->firstOrFail();

        abort_unless($user->skills()->whereKey($skillId)->exists(), 404);

        $user->skills()->updateExistingPivot($skillId, [
            'is_verified' => $verified,
            'verified_by' => $verified ? $actor->getKey() : null,
            'verified_at' => $verified ? now() : null,
        ]);

        activity('contributor')->causedBy($actor)->performedOn($profile)->event($verified ? 'skill_verified' : 'skill_unverified')
            ->withProperties(['skill_id' => $skillId])->log($verified ? 'Skill verified' : 'Skill verification removed');
    }
}
