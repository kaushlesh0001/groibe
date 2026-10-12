<?php

namespace App\Actions\Privacy;

use App\Enums\DataRequestStatus;
use App\Exceptions\ActionBlocked;
use App\Models\DataRequest;
use App\Models\User;
use App\Notifications\DataRequestUpdated;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;

/** `open | in_progress -> rejected` (spec 17.2, SCR-028). The reason is written for the person, who is told and can read it. */
class RejectDataRequest
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function __invoke(DataRequest $request, User $actor, string $reason): DataRequest
    {
        Gate::forUser($actor)->authorize('update', $request);

        if (trim($reason) === '') {
            throw ActionBlocked::guardFailed('Give the reason. The person can read it.');
        }

        $request->fill(['note' => trim($reason), 'handled_by' => $actor->getKey(), 'handled_at' => now()]);
        $request->moveTo(DataRequestStatus::Rejected);

        activity('privacy')->causedBy($actor)->performedOn($request)->event('rejected')->log('Data request rejected');

        $this->dispatcher->send($request->person(), new DataRequestUpdated($request, 'rejected'));

        return $request;
    }
}
