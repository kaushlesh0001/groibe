<?php

namespace App\Actions\Contributors;

use App\Actions\Contributors\Concerns\TransitionsContributor;
use App\Enums\OnboardingState;
use App\Exceptions\ActionBlocked;
use App\Models\Profile;
use App\Models\User;
use App\Services\Contributors\ProfileCompleteness;

/** registered -> profile_complete (spec 6.10). Requires the profile basics and at least one skill. */
class CompleteContributorProfile
{
    use TransitionsContributor;

    public function __construct(private ProfileCompleteness $completeness) {}

    public function __invoke(Profile $profile, User $actor): Profile
    {
        $completed = $this->move($profile, OnboardingState::ProfileComplete, $actor, 'completeProfile', guard: function (Profile $locked, User $user): void {
            $missing = $this->completeness->missingForProfile($user);

            if ($missing !== []) {
                throw ActionBlocked::profileIncomplete($missing);
            }
        });

        $completed->forceFill(['completed_at' => now()])->save();

        return $completed;
    }
}
