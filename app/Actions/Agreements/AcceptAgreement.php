<?php

namespace App\Actions\Agreements;

use App\Exceptions\ActionBlocked;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

/** Records one acceptance row (user, org, template version, ip, user agent, timestamp) and logs it (spec 7.7, 17.2). */
class AcceptAgreement
{
    public function __invoke(
        AgreementTemplate $template,
        User $user,
        ?Organization $organization = null,
        ?Project $project = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): AgreementAcceptance {
        if (! $template->is_active && $project === null) {
            throw ActionBlocked::guardFailed('That agreement version is no longer current. Please reload and accept the latest version.');
        }

        $acceptance = AgreementAcceptance::create([
            'template_id' => $template->getKey(),
            'user_id' => $user->getKey(),
            'organization_id' => $organization?->getKey(),
            'project_id' => $project?->getKey(),
            'accepted_at' => now(),
            'ip' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
        ]);

        activity('agreement')
            ->causedBy($user)
            ->performedOn($template)
            ->event('accepted')
            ->withProperties([
                'type' => $template->type->value,
                'version' => $template->version,
                'organization_id' => $organization?->getKey(),
                'project_id' => $project?->getKey(),
            ])
            ->log("Accepted {$template->type->value} v{$template->version}");

        return $acceptance;
    }
}
