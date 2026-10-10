<?php

namespace App\Policies;

use App\Models\Sow;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * Spec 5 "SOW": client R org and accept, delivery_manager CRU, finance R.
 * Drafts are internal and never visible to clients. Only an owner may accept (decision on spec 8 gating).
 */
class SowPolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $this->adminCan($actor, 'sows.view');
    }

    public function view(User $actor, Sow $sow): bool
    {
        if ($this->adminCan($actor, 'sows.view')) {
            return true;
        }

        return $sow->status->isClientVisible()
            && $this->isMember($actor, $this->organizationId($sow));
    }

    public function create(User $actor): bool
    {
        return $this->adminCan($actor, 'sows.create');
    }

    public function update(User $actor, Sow $sow): bool
    {
        return $this->adminCan($actor, 'sows.update');
    }

    public function send(User $actor, Sow $sow): bool
    {
        return $this->adminCan($actor, 'sows.update');
    }

    /** Role check only; the state machine (sent -> accepted) is enforced by AcceptSow. Drafts stay invisible. */
    public function accept(User $actor, Sow $sow): bool
    {
        return $actor->isClient()
            && $sow->status->isClientVisible()
            && $this->isOwner($actor, $this->organizationId($sow));
    }

    public function delete(User $actor, Sow $sow): bool
    {
        return false;
    }

    private function organizationId(Sow $sow): int
    {
        $sow->loadMissing('project:id,organization_id');

        return (int) $sow->project->organization_id;
    }
}
