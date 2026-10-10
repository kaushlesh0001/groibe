<?php

namespace App\Actions\OpenRoles;

use App\Actions\OpenRoles\Concerns\MovesOpenRole;
use App\Enums\OpenRoleStatus;
use App\Exceptions\ActionBlocked;
use App\Models\OpenRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** draft -> published (spec 6.4). A role needs a summary, a description and at least one position to go live. */
class PublishOpenRole
{
    use MovesOpenRole;

    public function __invoke(OpenRole $role, User $actor): OpenRole
    {
        Gate::forUser($actor)->authorize('update', $role);

        return $this->moveOpenRole($role, OpenRoleStatus::Published, $actor, ['published_at' => now(), 'closed_at' => null], function (OpenRole $locked): void {
            $missing = array_keys(array_filter([
                'a summary' => blank($locked->summary),
                'a description' => blank($locked->description),
                'at least one position' => $locked->positions_needed < 1,
            ]));

            if ($missing !== []) {
                throw ActionBlocked::guardFailed('Before publishing, the role needs '.implode(', ', $missing).'.');
            }
        });
    }
}
