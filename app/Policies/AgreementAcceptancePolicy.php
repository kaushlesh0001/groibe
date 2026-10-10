<?php

namespace App\Policies;

use App\Models\AgreementAcceptance;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/** Acceptances are append-only evidence: nobody updates or deletes them. */
class AgreementAcceptancePolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $this->adminCan($actor, 'agreements.view');
    }

    public function view(User $actor, AgreementAcceptance $acceptance): bool
    {
        return $acceptance->user_id === $actor->id || $this->adminCan($actor, 'agreements.view');
    }

    public function create(User $actor): bool
    {
        return $actor->isClient() || $actor->isContributor();
    }

    public function update(User $actor, AgreementAcceptance $acceptance): bool
    {
        return false;
    }

    public function delete(User $actor, AgreementAcceptance $acceptance): bool
    {
        return false;
    }
}
