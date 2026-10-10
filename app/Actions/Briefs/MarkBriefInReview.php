<?php

namespace App\Actions\Briefs;

use App\Actions\Briefs\Concerns\TransitionsBrief;
use App\Enums\BriefStatus;
use App\Models\Brief;
use App\Models\User;
use App\Notifications\BriefStatusChanged;
use App\Services\Notifications\NotificationDispatcher;

/** submitted -> in_review. */
class MarkBriefInReview
{
    use TransitionsBrief;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Brief $brief, User $actor): Brief
    {
        $brief = $this->move($brief, BriefStatus::InReview, $actor, 'markInReview', [
            'assigned_admin_id' => $brief->assigned_admin_id ?? $actor->getKey(),
        ]);

        $brief->loadMissing(['organization', 'submitter']);
        $this->dispatcher->toOrganization($brief->organization, new BriefStatusChanged($brief, BriefStatus::InReview), $brief->submitter);

        return $brief;
    }
}
