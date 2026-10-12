<?php

namespace App\Actions\Privacy;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Exceptions\ActionBlocked;
use App\Models\DataRequest;
use App\Models\User;
use App\Services\Privacy\UserAnonymiser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * `in_progress -> completed` for an erasure request (spec 17.2, SCR-028): the person's data is anonymised and the account is
 * soft-deleted, never hard-deleted, so payouts and the audit trail stay intact. It is refused while the person has work or money
 * in progress, and the refusal says what to settle. All of it happens in one transaction: the request is completed only if the
 * erasure was. The person is not notified, because the account no longer exists to be told.
 */
class CompleteDeletionRequest
{
    public function __construct(private readonly UserAnonymiser $anonymiser) {}

    public function __invoke(DataRequest $request, User $actor, ?string $note = null): DataRequest
    {
        Gate::forUser($actor)->authorize('update', $request);

        if ($request->type !== DataRequestType::Deletion) {
            throw ActionBlocked::guardFailed('This is an export request. Complete it as an export.');
        }

        $person = $request->person();
        $blockers = $this->anonymiser->blockers($person);

        if ($blockers !== []) {
            throw ActionBlocked::guardFailed('This person cannot be erased yet. '.implode(' ', $blockers));
        }

        DB::transaction(function () use ($request, $actor, $note, $person): void {
            $request->fill(['note' => $note === null || trim($note) === '' ? null : trim($note), 'handled_by' => $actor->getKey(), 'handled_at' => now()]);
            $request->moveTo(DataRequestStatus::Completed);

            $this->anonymiser->anonymise($person);

            activity('privacy')->causedBy($actor)->performedOn($request)->event('erased')
                ->withProperties(['user_id' => $person->getKey()])->log('Personal data erased and account closed');
        });

        return $request;
    }
}
