<?php

namespace App\Services\Agreements;

use App\Enums\AgreementType;
use App\Enums\OrgRole;
use App\Exceptions\ActionBlocked;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\Organization;
use App\Models\OrganizationUser;

/** Spec 8 gating for clients. Fails closed: no published Client MSA means nobody can submit or accept. */
class AgreementGate
{
    /** True when an owner of the organization has accepted the currently active Client MSA version. */
    public function hasAcceptedClientMsa(Organization $organization): bool
    {
        $template = AgreementTemplate::currentFor(AgreementType::ClientMsa);

        if ($template === null) {
            return false;
        }

        return AgreementAcceptance::query()
            ->where('template_id', $template->getKey())
            ->where('organization_id', $organization->getKey())
            ->whereIn('user_id', OrganizationUser::query()
                ->where('organization_id', $organization->getKey())
                ->where('org_role', OrgRole::Owner->value)
                ->select('user_id'))
            ->exists();
    }

    /** Spec 6.11: a brief needs a verified organization, or the admin override `can_submit_briefs`. */
    public function organizationMaySubmitBriefs(Organization $organization): bool
    {
        return $organization->isVerified() || $organization->can_submit_briefs;
    }

    public function assertMayRaiseBrief(Organization $organization): void
    {
        if (! $this->organizationMaySubmitBriefs($organization)) {
            throw ActionBlocked::organizationNotVerified();
        }

        $this->assertClientMsaAccepted($organization);
    }

    public function assertClientMsaAccepted(Organization $organization): void
    {
        if (! $this->hasAcceptedClientMsa($organization)) {
            throw ActionBlocked::clientMsaNotAccepted();
        }
    }
}
