<?php

namespace App\Actions\Briefs;

use App\Actions\Briefs\Concerns\TransitionsBrief;
use App\Enums\BriefStatus;
use App\Models\Brief;
use App\Models\User;
use App\Notifications\BriefStatusChanged;
use App\Services\Notifications\NotificationDispatcher;

/** in_review -> declined. The optional reason goes to the client and the activity log. */
class DeclineBrief
{
    use TransitionsBrief;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Brief $brief, User $actor, ?string $reason = null): Brief
    {
        $reason = filled($reason) ? trim($reason) : null;

        $brief = $this->move($brief, BriefStatus::Declined, $actor, 'decline', properties: ['reason' => $reason]);

        $brief->loadMissing(['organization', 'submitter']);
        $this->dispatcher->toOrganization($brief->organization, new BriefStatusChanged($brief, BriefStatus::Declined, $reason), $brief->submitter);

        return $brief;
    }
}
