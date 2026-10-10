<?php

namespace App\Actions\Applications;

use App\Actions\Applications\Concerns\MovesApplication;
use App\Actions\Assignments\SeatContributor;
use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Enums\OpenRoleStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Application;
use App\Models\OpenRole;
use App\Models\User;
use App\Notifications\ApplicationUpdated;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;

/**
 * offered -> accepted, by the applicant (spec 6.5). On a role linked to a project this seats the contributor as an
 * `active` assignment through the same rules as an admin invite (spec 6.2, 6.6, SCR-014). When accepted offers reach
 * `positions_needed` the role becomes `filled` (spec 6.4). Anything that blocks the seat rolls the whole acceptance back.
 */
class AcceptOffer
{
    use MovesApplication;

    public function __construct(private SeatContributor $seat, private NotificationDispatcher $dispatcher) {}

    public function __invoke(Application $application, User $actor): Application
    {
        Gate::forUser($actor)->authorize('respond', $application);

        $moved = $this->moveApplication($application, ApplicationStatus::Accepted, $actor, [], [], function (Application $locked) use ($actor): void {
            $role = OpenRole::query()->lockForUpdate()->findOrFail($locked->open_role_id);

            if ($role->status !== OpenRoleStatus::Published) {
                throw ActionBlocked::guardFailed('This role is no longer open, so the offer can no longer be accepted. Please contact Groibe.');
            }

            if ($role->project_id !== null) {
                ($this->seat)($role->project()->firstOrFail(), $actor, $actor, ['role_title' => $role->title], AssignmentStatus::Active);
            }

            $accepted = Application::query()
                ->where('open_role_id', $role->getKey())
                ->where('status', ApplicationStatus::Accepted->value)
                ->count();

            if ($accepted >= $role->positions_needed) {
                $role->forceFill(['status' => OpenRoleStatus::Filled, 'closed_at' => now()])->save();

                activity('open_role')
                    ->causedBy($actor)
                    ->performedOn($role)
                    ->event('filled')
                    ->withProperties(['from' => OpenRoleStatus::Published->value, 'to' => OpenRoleStatus::Filled->value, 'accepted' => $accepted])
                    ->log('Open role filled');
            }
        });

        $this->dispatcher->toAdmins('applications.update', new ApplicationUpdated($moved, 'accepted', toAdmin: true));

        return $moved;
    }
}
