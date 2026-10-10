<?php

namespace App\Actions\OpenRoles;

use App\Actions\OpenRoles\Concerns\MovesOpenRole;
use App\Enums\OpenRoleStatus;
use App\Models\OpenRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** published -> closed (spec 6.4): no more applications. Existing applications stay as they are. */
class CloseOpenRole
{
    use MovesOpenRole;

    public function __invoke(OpenRole $role, User $actor): OpenRole
    {
        Gate::forUser($actor)->authorize('update', $role);

        return $this->moveOpenRole($role, OpenRoleStatus::Closed, $actor, ['closed_at' => now()]);
    }
}
