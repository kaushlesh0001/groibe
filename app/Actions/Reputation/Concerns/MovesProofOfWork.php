<?php

namespace App\Actions\Reputation\Concerns;

use App\Enums\ProofStatus;
use App\Exceptions\IllegalTransition;
use App\Models\ProofOfWork;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/** Shared transition mechanics for proof of work: lock, validate the move, persist, log. Callers authorize first. */
trait MovesProofOfWork
{
    /**
     * @param  array<string, mixed>  $attributes  extra columns to write with the status change
     * @param  array<string, mixed>  $properties  extra data for the activity log entry
     * @param  Closure(ProofOfWork): void|null  $guard  runs inside the transaction after the state check
     */
    protected function moveProof(
        ProofOfWork $proof,
        ProofStatus $to,
        User $actor,
        array $attributes = [],
        array $properties = [],
        ?Closure $guard = null,
    ): ProofOfWork {
        DB::transaction(function () use ($proof, $to, $actor, $attributes, $properties, $guard): void {
            $locked = ProofOfWork::query()->lockForUpdate()->findOrFail($proof->getKey());
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw IllegalTransition::for('proof of work', $from->value, $to->value);
            }

            if ($guard !== null) {
                $guard($locked);
            }

            $locked->forceFill(['status' => $to, ...$attributes])->save();

            activity('proof_of_work')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event($to->value)
                ->withProperties(['from' => $from->value, 'to' => $to->value, ...$properties])
                ->log("Proof of work moved from {$from->value} to {$to->value}");
        });

        return $proof->refresh();
    }
}
