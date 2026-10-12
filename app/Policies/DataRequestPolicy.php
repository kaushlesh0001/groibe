<?php

namespace App\Policies;

use App\Models\DataRequest;
use App\Models\User;

/**
 * Data requests (spec 17.2, SCR-028). Any contributor or client may open one for themselves, whatever their review state: a
 * suspended contributor keeps this right. Only a super admin reads and processes them, because a request names a person and
 * what they want erased.
 */
class DataRequestPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin() && $actor->can('data_requests.view');
    }

    public function view(User $actor, DataRequest $request): bool
    {
        return $this->viewAny($actor);
    }

    /** Opening a request, for oneself. */
    public function create(User $actor): bool
    {
        return $actor->isContributor() || $actor->isClient();
    }

    /** Starting, completing or rejecting a request, and downloading a person's export. */
    public function update(User $actor, DataRequest $request): bool
    {
        return $actor->isAdmin() && $actor->can('data_requests.update');
    }

    public function delete(User $actor, DataRequest $request): bool
    {
        return false;
    }
}
