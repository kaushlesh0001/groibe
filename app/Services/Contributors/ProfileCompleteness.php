<?php

namespace App\Services\Contributors;

use App\Enums\AgreementType;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\User;

/** Spec 6.10 and 11: what a contributor still owes before each onboarding transition. */
class ProfileCompleteness
{
    /** @return list<string> human readable gaps for `registered -> profile_complete` */
    public function missingForProfile(User $user): array
    {
        $profile = $user->profile;
        $missing = [];

        if ($profile === null) {
            return ['Profile'];
        }

        foreach (['headline' => 'Headline', 'bio' => 'Short bio', 'city' => 'City', 'country' => 'Country'] as $field => $label) {
            if (blank($profile->{$field})) {
                $missing[] = $label;
            }
        }

        if ($profile->availability === null) {
            $missing[] = 'Availability';
        }

        if ($user->skills()->count() === 0) {
            $missing[] = 'At least one skill with a level';
        }

        return $missing;
    }

    public function hasAcceptedMemberAgreement(User $user): bool
    {
        $template = AgreementTemplate::currentFor(AgreementType::MemberAgreement);

        if ($template === null) {
            return false;
        }

        return AgreementAcceptance::query()
            ->where('template_id', $template->getKey())
            ->where('user_id', $user->getKey())
            ->exists();
    }
}
