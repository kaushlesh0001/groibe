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

/** shortlisted -> offered (spec 6.5). An approval, so it needs `applications.approve`. Only while the role is published. */
class OfferApplication
{
    use MovesApplication;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Application $application, User $actor, ?string $note = null): Application
    {
        Gate::forUser($actor)->authorize('approve', $application);

        $application->loadMissing('openRole', 'user');

        if ($application->openRole->status !== OpenRoleStatus::Published) {
            throw ActionBlocked::guardFailed('Offers can only be made on a published role.');
        }

        $moved = $this->moveApplication($application, ApplicationStatus::Offered, $actor, [
            'decided_at' => now(),
            'decided_by' => $actor->getKey(),
            'admin_note' => $note ?? $application->admin_note,
        ]);

        $this->dispatcher->send($application->user, new ApplicationUpdated($moved, 'offered'));

        return $moved;
    }
}
