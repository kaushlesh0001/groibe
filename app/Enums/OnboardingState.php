<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/**
 * Contributor states per spec 6.10 and client states per spec 6.11, stored in profiles.onboarding_state.
 * Contributor transitions are enforced by the Actions in app/Actions/Contributors (Pass 3).
 */
enum OnboardingState: string implements HasLabel
{
    use LabelTrait;

    case Registered = 'registered';
    case ProfileComplete = 'profile_complete';
    case PendingReview = 'pending_review';
    case Active = 'active';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case VerifiedEmail = 'verified_email';
    case OrgPending = 'org_pending';
    case OrgVerified = 'org_verified';

    /**
     * Spec 6.10 with SCR-011: `rejected` is terminal in v1, `suspended` can be reinstated.
     *
     * @return list<self>
     */
    public function allowedContributorTransitions(): array
    {
        return match ($this) {
            self::Registered => [self::ProfileComplete],
            self::ProfileComplete => [self::PendingReview],
            self::PendingReview => [self::Active, self::Rejected],
            self::Active => [self::Suspended],
            self::Suspended => [self::Active],
            default => [],
        };
    }

    public function canContributorTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedContributorTransitions(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Active, self::OrgVerified => 'success',
            self::PendingReview, self::OrgPending => 'warning',
            self::Rejected, self::Suspended => 'danger',
            self::ProfileComplete, self::VerifiedEmail => 'info',
            self::Registered => 'gray',
        };
    }
}
