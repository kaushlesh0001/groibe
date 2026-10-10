<?php

namespace App\Actions\Briefs;

use App\Actions\Briefs\Concerns\TransitionsBrief;
use App\Enums\BriefStatus;
use App\Models\Brief;
use App\Models\User;
use App\Notifications\BriefStatusChanged;
use App\Services\Notifications\NotificationDispatcher;

/** in_review -> scoped (SCR-005). Convert to Project requires this state. */
class MarkBriefScoped
{
    use TransitionsBrief;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Brief $brief, User $actor): Brief
    {
        $brief = $this->move($brief, BriefStatus::Scoped, $actor, 'markScoped');

        $brief->loadMissing(['organization', 'submitter']);
        $this->dispatcher->toOrganization($brief->organization, new BriefStatusChanged($brief, BriefStatus::Scoped), $brief->submitter);

        return $brief;
    }
}
