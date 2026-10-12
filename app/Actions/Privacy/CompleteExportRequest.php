<?php

namespace App\Actions\Privacy;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Exceptions\ActionBlocked;
use App\Models\DataRequest;
use App\Models\User;
use App\Notifications\DataRequestUpdated;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;

/**
 * `in_progress -> completed` for an export request (spec 17.2, SCR-028). The super admin has delivered the copy outside the
 * platform and says how in the note, which the person can read. The person is told.
 */
class CompleteExportRequest
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function __invoke(DataRequest $request, User $actor, string $note): DataRequest
    {
        Gate::forUser($actor)->authorize('update', $request);

        if ($request->type !== DataRequestType::Export) {
            throw ActionBlocked::guardFailed('This is an erasure request. Complete it as an erasure.');
        }

        if (trim($note) === '') {
            throw ActionBlocked::guardFailed('Say how the copy was delivered. The person can read this note.');
        }

        $request->fill(['note' => trim($note), 'handled_by' => $actor->getKey(), 'handled_at' => now()]);
        $request->moveTo(DataRequestStatus::Completed);

        activity('privacy')->causedBy($actor)->performedOn($request)->event('completed')->log('Data export completed');

        $this->dispatcher->send($request->person(), new DataRequestUpdated($request, 'completed'));

        return $request;
    }
}
