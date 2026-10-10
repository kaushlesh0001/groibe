<?php

namespace App\Actions\Briefs;

use App\Actions\Briefs\Concerns\TransitionsBrief;
use App\Enums\BriefStatus;
use App\Models\Brief;
use App\Models\User;
use App\Notifications\BriefStatusChanged;
use App\Services\Notifications\NotificationDispatcher;

/** needs_info -> in_review. The client sends their answers back, or an admin resumes the review. */
class ResumeBrief
{
    use TransitionsBrief;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Brief $brief, User $actor): Brief
    {
        $brief = $this->move($brief, BriefStatus::InReview, $actor, 'resume');

        $brief->loadMissing(['organization', 'submitter', 'assignedAdmin']);

        if ($actor->isAdmin()) {
            $this->dispatcher->toOrganization($brief->organization, new BriefStatusChanged($brief, BriefStatus::InReview), $brief->submitter);
        } else {
            $this->dispatcher->toAdmins('briefs.update', new BriefStatusChanged($brief, BriefStatus::InReview, forAdmins: true), $brief->assignedAdmin);
        }

        return $brief;
    }
}
