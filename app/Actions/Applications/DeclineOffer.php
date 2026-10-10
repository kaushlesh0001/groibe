<?php

namespace App\Actions\Applications;

use App\Actions\Applications\Concerns\MovesApplication;
use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\User;
use App\Notifications\ApplicationUpdated;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;

/** offered -> declined, by the applicant (spec 6.5). */
class DeclineOffer
{
    use MovesApplication;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Application $application, User $actor): Application
    {
        Gate::forUser($actor)->authorize('respond', $application);

        $moved = $this->moveApplication($application, ApplicationStatus::Declined, $actor);

        $this->dispatcher->toAdmins('applications.update', new ApplicationUpdated($moved, 'declined', toAdmin: true));

        return $moved;
    }
}
