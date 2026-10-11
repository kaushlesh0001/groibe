<?php

namespace App\Actions\Reputation;

use App\Actions\Reputation\Concerns\MovesProofOfWork;
use App\Enums\ProofStatus;
use App\Models\ProofOfWork;
use App\Models\User;
use App\Notifications\ProofOfWorkChanged;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;

/** pending_approval -> draft: an admin sends the wording back with a note (Pass 6 decision). */
class ReturnProofToDraft
{
    use MovesProofOfWork;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(ProofOfWork $proof, User $actor, ?string $note = null): ProofOfWork
    {
        Gate::forUser($actor)->authorize('approve', $proof);

        $moved = $this->moveProof($proof, ProofStatus::Draft, $actor, properties: ['note' => $note]);

        $this->dispatcher->send($moved->user, new ProofOfWorkChanged($moved, 'returned', $note));

        return $moved;
    }
}
