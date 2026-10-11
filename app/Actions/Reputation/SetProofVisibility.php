<?php

namespace App\Actions\Reputation;

use App\Enums\ProofVisibility;
use App\Models\ProofOfWork;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** The contributor's own visibility toggle (spec 5 "Proof of Work"). Setting `public` does not publish: that stays an admin approval. */
class SetProofVisibility
{
    public function __invoke(ProofOfWork $proof, User $actor, ProofVisibility $visibility): ProofOfWork
    {
        Gate::forUser($actor)->authorize('setVisibility', $proof);

        $proof->forceFill(['visibility' => $visibility])->save();

        return $proof->refresh();
    }
}
