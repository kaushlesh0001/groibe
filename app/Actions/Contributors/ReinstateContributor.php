<?php

namespace App\Actions\Contributors;

use App\Actions\Contributors\Concerns\TransitionsContributor;
use App\Enums\OnboardingState;
use App\Enums\UserStatus;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\ContributorStatusChanged;
use App\Services\Notifications\NotificationDispatcher;

/** suspended -> active (SCR-011). */
class ReinstateContributor
{
    use TransitionsContributor;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Profile $profile, User $actor, ?string $reason = null): Profile
    {
        $reinstated = $this->move($profile, OnboardingState::Active, $actor, 'reinstate', UserStatus::Active, array_filter(['reason' => $reason]), requiredFrom: OnboardingState::Suspended);

        $reinstated->loadMissing('user');
        $this->dispatcher->send($reinstated->user, new ContributorStatusChanged($reinstated, OnboardingState::Active, reinstated: true));

        return $reinstated;
    }
}
