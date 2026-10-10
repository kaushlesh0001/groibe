<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementType;
use App\Enums\OrgRole;
use App\Exceptions\ActionBlocked;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** The organization owner accepts the current Client MSA (spec 8). Idempotent per version. */
class AcceptClientMsa
{
    public function __construct(private AcceptAgreement $accept) {}

    public function __invoke(User $actor, Organization $organization, ?string $ip = null, ?string $userAgent = null): AgreementAcceptance
    {
        if ($actor->organizationRole($organization) !== OrgRole::Owner) {
            throw new AuthorizationException('Only the organization owner can accept the Client MSA.');
        }

        $template = AgreementTemplate::currentFor(AgreementType::ClientMsa)
            ?? throw ActionBlocked::missingTemplate('Client MSA');

        $existing = AgreementAcceptance::query()
            ->where('template_id', $template->getKey())
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $actor->getKey())
            ->first();

        return $existing ?? ($this->accept)($template, $actor, $organization, null, $ip, $userAgent);
    }
}
