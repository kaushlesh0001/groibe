<?php

namespace App\Actions\Contributors;

use App\Actions\Contributors\Concerns\TransitionsContributor;
use App\Enums\OnboardingState;
use App\Enums\UserStatus;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\ContributorStatusChanged;
use App\Services\Notifications\NotificationDispatcher;

/** active -> suspended (spec 6.10). The reason is kept in the activity log only. */
class SuspendContributor
{
    use TransitionsContributor;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Profile $profile, User $actor, string $reason): Profile
    {
        $suspended = $this->move($profile, OnboardingState::Suspended, $actor, 'suspend', UserStatus::Suspended, ['reason' => $reason]);

        $suspended->loadMissing('user');
        $this->dispatcher->send($suspended->user, new ContributorStatusChanged($suspended, OnboardingState::Suspended));

        return $suspended;
    }
}
