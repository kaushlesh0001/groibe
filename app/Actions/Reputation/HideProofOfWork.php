<?php

namespace App\Actions\Reputation;

use App\Actions\Reputation\Concerns\MovesProofOfWork;
use App\Enums\ProofStatus;
use App\Models\ProofOfWork;
use App\Models\User;
use App\Notifications\ProofOfWorkChanged;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;

/** published -> hidden (spec 7.5). Removes the item from every public surface at once. */
class HideProofOfWork
{
    use MovesProofOfWork;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(ProofOfWork $proof, User $actor, ?string $note = null): ProofOfWork
    {
        Gate::forUser($actor)->authorize('approve', $proof);

        $moved = $this->moveProof($proof, ProofStatus::Hidden, $actor, properties: ['note' => $note]);

        $this->dispatcher->send($moved->user, new ProofOfWorkChanged($moved, 'hidden', $note));

        return $moved;
    }
}
