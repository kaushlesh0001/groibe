<?php

namespace App\Actions\Contributors;

use App\Actions\Agreements\AcceptAgreement;
use App\Enums\AgreementType;
use App\Exceptions\ActionBlocked;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\User;
use App\Services\Admin\Impersonation;

/** Records acceptance of the current Member Agreement (spec 8). Idempotent for the same version. */
class AcceptMemberAgreement
{
    public function __construct(private AcceptAgreement $accept) {}

    public function __invoke(User $user, ?string $ip = null, ?string $userAgent = null): AgreementAcceptance
    {
        app(Impersonation::class)->assertNotImpersonating();

        $template = AgreementTemplate::currentPublishedFor(AgreementType::MemberAgreement)
            ?? throw ActionBlocked::missingTemplate('Member Agreement');

        $existing = AgreementAcceptance::query()
            ->where('template_id', $template->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        return $existing ?? ($this->accept)($template, $user, null, null, $ip, $userAgent);
    }
}
