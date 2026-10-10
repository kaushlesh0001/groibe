<?php

namespace App\Actions\OpenRoles;

use App\Actions\OpenRoles\Concerns\MovesOpenRole;
use App\Enums\OpenRoleStatus;
use App\Models\OpenRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** published -> archived (spec 6.4): take a live role off the board without closing it as filled. */
class ArchiveOpenRole
{
    use MovesOpenRole;

    public function __invoke(OpenRole $role, User $actor): OpenRole
    {
        Gate::forUser($actor)->authorize('update', $role);

        return $this->moveOpenRole($role, OpenRoleStatus::Archived, $actor, ['closed_at' => now()]);
    }
}
