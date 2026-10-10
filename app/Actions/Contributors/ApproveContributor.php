<?php

namespace App\Actions\Contributors;

use App\Actions\Contributors\Concerns\TransitionsContributor;
use App\Enums\OnboardingState;
use App\Enums\UserStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\ContributorStatusChanged;
use App\Services\Contributors\ProfileCompleteness;
use App\Services\Notifications\NotificationDispatcher;

/** pending_review -> active (spec 6.10). Re-checks email and Member Agreement so admins cannot skip the gates. */
class ApproveContributor
{
    use TransitionsContributor;

    public function __construct(private ProfileCompleteness $completeness, private NotificationDispatcher $dispatcher) {}

    public function __invoke(Profile $profile, User $actor): Profile
    {
        $approved = $this->move($profile, OnboardingState::Active, $actor, 'approve', UserStatus::Active, guard: function (Profile $locked, User $user): void {
            if ($user->email_verified_at === null) {
                throw ActionBlocked::emailNotVerified();
            }

            if (! $this->completeness->hasAcceptedMemberAgreement($user)) {
                throw ActionBlocked::memberAgreementNotAccepted();
            }
        }, requiredFrom: OnboardingState::PendingReview);

        $approved->loadMissing('user');
        $this->dispatcher->send($approved->user, new ContributorStatusChanged($approved, OnboardingState::Active));

        return $approved;
    }
}
