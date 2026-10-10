<?php

namespace App\Actions\Contributors;

use App\Actions\Contributors\Concerns\TransitionsContributor;
use App\Enums\OnboardingState;
use App\Exceptions\ActionBlocked;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\ContributorStatusChanged;
use App\Services\Contributors\ProfileCompleteness;
use App\Services\Notifications\NotificationDispatcher;

/**
 * profile_complete -> pending_review (spec 6.10). Needs a verified email and, per the registration
 * flow in spec 11, the current Member Agreement accepted before the profile reaches a reviewer.
 */
class SubmitContributorForReview
{
    use TransitionsContributor;

    public function __construct(private ProfileCompleteness $completeness, private NotificationDispatcher $dispatcher) {}

    public function __invoke(Profile $profile, User $actor): Profile
    {
        $submitted = $this->move($profile, OnboardingState::PendingReview, $actor, 'submitForReview', guard: function (Profile $locked, User $user): void {
            if ($user->email_verified_at === null) {
                throw ActionBlocked::emailNotVerified();
            }

            if (! $this->completeness->hasAcceptedMemberAgreement($user)) {
                throw ActionBlocked::memberAgreementNotAccepted();
            }
        });

        $submitted->loadMissing('user');
        $this->dispatcher->send($submitted->user, new ContributorStatusChanged($submitted, OnboardingState::PendingReview));
        $this->dispatcher->toAdmins('contributors.approve', new ContributorStatusChanged($submitted, OnboardingState::PendingReview, forAdmins: true));

        return $submitted;
    }
}
