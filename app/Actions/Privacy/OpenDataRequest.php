<?php

namespace App\Actions\Privacy;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Exceptions\ActionBlocked;
use App\Models\DataRequest;
use App\Models\User;
use App\Notifications\DataRequestUpdated;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A contributor or client asks for a copy of their data, or for its erasure (spec 17.2, SCR-028). One request of each kind at a
 * time: a second one while the first waits is refused. Super admins are told. The request is the person's own, for themselves.
 */
class OpenDataRequest
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function __invoke(User $user, DataRequestType $type): DataRequest
    {
        Gate::forUser($user)->authorize('create', DataRequest::class);

        $request = DB::transaction(function () use ($user, $type): DataRequest {
            // Two clicks at once serialize on the person's row, so both cannot pass the check below.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $waiting = DataRequest::query()->where('user_id', $user->getKey())->where('type', $type->value)->pending()->exists();

            if ($waiting) {
                throw ActionBlocked::guardFailed('You already have a request of this kind waiting. Groibe will answer it soon.');
            }

            $request = DataRequest::query()->create([
                'user_id' => $user->getKey(),
                'type' => $type,
                'status' => DataRequestStatus::Open,
            ]);

            activity('privacy')->causedBy($user)->performedOn($request)->event('requested')
                ->withProperties(['type' => $type->value])->log("Data {$type->value} requested");

            return $request;
        });

        $this->dispatcher->toAdmins('data_requests.update', new DataRequestUpdated($request, 'received', toAdmin: true));

        return $request;
    }
}
