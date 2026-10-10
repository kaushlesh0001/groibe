<?php

namespace App\Actions\Briefs;

use App\Actions\Briefs\Concerns\TransitionsBrief;
use App\Enums\BriefStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Brief;
use App\Models\User;
use App\Notifications\BriefStatusChanged;
use App\Services\Agreements\AgreementGate;
use App\Services\Notifications\NotificationDispatcher;

/** draft -> submitted. Needs a verified organization (or the admin override) and an accepted Client MSA. */
class SubmitBrief
{
    use TransitionsBrief;

    public function __construct(private AgreementGate $gate, private NotificationDispatcher $dispatcher) {}

    public function __invoke(Brief $brief, User $actor): Brief
    {
        $brief = $this->move($brief, BriefStatus::Submitted, $actor, 'submit', [
            'submitted_by' => $actor->getKey(),
            'submitted_at' => now(),
        ], guard: function (Brief $locked): void {
            if (blank($locked->title) || blank($locked->problem) || blank($locked->desired_outcome)) {
                throw ActionBlocked::guardFailed('A brief needs a title, the problem and the desired outcome before it can be submitted.');
            }

            $this->gate->assertMayRaiseBrief($locked->loadMissing('organization')->organization);
        });

        $brief->loadMissing(['organization', 'assignedAdmin']);

        $this->dispatcher->toAdmins('briefs.update', new BriefStatusChanged($brief, BriefStatus::Submitted, forAdmins: true), $brief->assignedAdmin);
        $this->dispatcher->toOrganization($brief->organization, new BriefStatusChanged($brief, BriefStatus::Submitted), $actor);

        return $brief;
    }
}
