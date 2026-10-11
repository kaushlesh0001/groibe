<?php

namespace App\Actions\Reputation;

use App\Actions\Reputation\Concerns\MovesProofOfWork;
use App\Enums\ProofStatus;
use App\Exceptions\ActionBlocked;
use App\Models\ProofOfWork;
use App\Models\User;
use App\Notifications\ProofOfWorkChanged;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;

/** draft -> pending_approval (spec 9, 11). The contributor must have written a summary and a contribution text. */
class RequestProofPublication
{
    use MovesProofOfWork;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(ProofOfWork $proof, User $actor): ProofOfWork
    {
        Gate::forUser($actor)->authorize('requestPublication', $proof);

        $moved = $this->moveProof($proof, ProofStatus::PendingApproval, $actor, guard: function (ProofOfWork $locked): void {
            if (blank($locked->summary) || blank($locked->contribution_text)) {
                throw ActionBlocked::guardFailed('Add a short summary and describe your contribution before asking for publication.');
            }
        });

        $this->dispatcher->toAdmins('proof_of_work.approve', new ProofOfWorkChanged($moved, 'submitted', toAdmin: true));

        return $moved;
    }
}
