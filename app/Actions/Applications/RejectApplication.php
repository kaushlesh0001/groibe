<?php

namespace App\Actions\Applications;

use App\Actions\Applications\Concerns\MovesApplication;
use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\User;
use App\Notifications\ApplicationUpdated;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;

/** submitted | shortlisted | offered -> rejected (spec 6.5). Allowed on any role status so closed roles can be tidied up. */
class RejectApplication
{
    use MovesApplication;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Application $application, User $actor, ?string $note = null): Application
    {
        Gate::forUser($actor)->authorize('update', $application);

        $application->loadMissing('openRole', 'user');

        $moved = $this->moveApplication($application, ApplicationStatus::Rejected, $actor, [
            'decided_at' => now(),
            'decided_by' => $actor->getKey(),
            'admin_note' => $note ?? $application->admin_note,
        ]);

        $this->dispatcher->send($application->user, new ApplicationUpdated($moved, 'rejected'));

        return $moved;
    }
}
