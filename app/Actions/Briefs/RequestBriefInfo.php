<?php

namespace App\Actions\Briefs;

use App\Actions\Briefs\Concerns\TransitionsBrief;
use App\Enums\BriefStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Brief;
use App\Models\User;
use App\Notifications\BriefStatusChanged;
use App\Services\Notifications\NotificationDispatcher;

/** in_review -> needs_info. The note is the question the client sees. */
class RequestBriefInfo
{
    use TransitionsBrief;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Brief $brief, User $actor, string $note): Brief
    {
        $note = trim($note);

        if ($note === '') {
            throw ActionBlocked::guardFailed('Tell the client what information is needed.');
        }

        $brief = $this->move($brief, BriefStatus::NeedsInfo, $actor, 'requestInfo', ['needs_info_note' => $note]);

        $brief->loadMissing(['organization', 'submitter']);
        $this->dispatcher->toOrganization($brief->organization, new BriefStatusChanged($brief, BriefStatus::NeedsInfo, $note), $brief->submitter);

        return $brief;
    }
}
