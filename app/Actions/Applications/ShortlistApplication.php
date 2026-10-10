<?php

namespace App\Actions\Applications;

use App\Actions\Applications\Concerns\MovesApplication;
use App\Enums\ApplicationStatus;
use App\Enums\OpenRoleStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Application;
use App\Models\User;
use App\Notifications\ApplicationUpdated;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;

/** submitted -> shortlisted (spec 6.5). Only while the role is still published. */
class ShortlistApplication
{
    use MovesApplication;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Application $application, User $actor, ?string $note = null): Application
    {
        Gate::forUser($actor)->authorize('update', $application);

        $application->loadMissing('openRole', 'user');

        if ($application->openRole->status !== OpenRoleStatus::Published) {
            throw ActionBlocked::guardFailed('Only applications to a published role can be shortlisted.');
        }

        $moved = $this->moveApplication($application, ApplicationStatus::Shortlisted, $actor, [
            'shortlisted_at' => now(),
            'admin_note' => $note ?? $application->admin_note,
        ]);

        $this->dispatcher->send($application->user, new ApplicationUpdated($moved, 'shortlisted'));

        return $moved;
    }
}
