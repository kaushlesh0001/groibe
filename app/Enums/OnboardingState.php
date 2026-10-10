<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/**
 * Contributor states per spec 6.10 and client states per spec 6.11, stored in profiles.onboarding_state.
 * Transition Actions arrive in the onboarding passes; Pass 1 only stores the initial `registered` state.
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
}
