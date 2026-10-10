<?php

namespace App\Actions\Contributors;

use App\Actions\Contributors\Concerns\TransitionsContributor;
use App\Enums\OnboardingState;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\ContributorStatusChanged;
use App\Services\Notifications\NotificationDispatcher;

/** pending_review -> rejected (spec 6.10, terminal in v1 per SCR-011). The reason is kept in the activity log only. */
class RejectContributor
{
    use TransitionsContributor;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Profile $profile, User $actor, string $reason): Profile
    {
        $rejected = $this->move($profile, OnboardingState::Rejected, $actor, 'reject', properties: ['reason' => $reason]);

        $rejected->loadMissing('user');
        $this->dispatcher->send($rejected->user, new ContributorStatusChanged($rejected, OnboardingState::Rejected));

        return $rejected;
    }
}
