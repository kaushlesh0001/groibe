<?php

namespace App\Actions\Privacy;

use App\Enums\DataRequestStatus;
use App\Models\DataRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** `open -> in_progress`: a super admin takes the request (spec 17.2, SCR-028). */
class StartDataRequest
{
    public function __invoke(DataRequest $request, User $actor): DataRequest
    {
        Gate::forUser($actor)->authorize('update', $request);

        $request->handled_by = $actor->getKey();
        $request->moveTo(DataRequestStatus::InProgress);

        activity('privacy')->causedBy($actor)->performedOn($request)->event('started')->log('Data request taken');

        return $request;
    }
}
