<?php

namespace App\Policies;

use App\Enums\ProofStatus;
use App\Models\ProofOfWork;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * Spec 5 "Proof of Work": contributor RU own text and toggles visibility, delivery_manager CRUA, community_manager RUA.
 * Public reading is not a policy matter: it goes through ProofOfWork::publiclyVisible() on the public routes.
 * Pass 6 decisions: the owner edits text only while the item is a draft (a published item changes only through
 * admin approval), and clients never read proof of work here (their control is the project's showcase consent).
 */
class ProofOfWorkPolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $actor->isContributor() || $this->adminCan($actor, 'proof_of_work.view');
    }

    public function view(User $actor, ProofOfWork $proof): bool
    {
        return $this->adminCan($actor, 'proof_of_work.view')
            || ($actor->isContributor() && $proof->user_id === $actor->getKey());
    }

    public function create(User $actor): bool
    {
        return $this->adminCan($actor, 'proof_of_work.create');
    }

    /** Admin edits at any state; the owner edits their own draft (text and metrics). */
    public function update(User $actor, ProofOfWork $proof): bool
    {
        return $this->adminCan($actor, 'proof_of_work.update') || $this->edit($actor, $proof);
    }

    public function edit(User $actor, ProofOfWork $proof): bool
    {
        return $actor->isActiveContributor()
            && $proof->user_id === $actor->getKey()
            && $proof->status === ProofStatus::Draft;
    }

    /** Owner asks for publication: draft to pending approval. */
    public function requestPublication(User $actor, ProofOfWork $proof): bool
    {
        return $this->edit($actor, $proof);
    }

    /** Owner toggles visibility on their own item, unless an admin has hidden it. */
    public function setVisibility(User $actor, ProofOfWork $proof): bool
    {
        return $actor->isActiveContributor()
            && $proof->user_id === $actor->getKey()
            && $proof->status !== ProofStatus::Hidden;
    }

    /** Approve, send back, hide and re-publish. */
    public function approve(User $actor, ProofOfWork $proof): bool
    {
        return $this->adminCan($actor, 'proof_of_work.approve');
    }

    public function delete(User $actor, ProofOfWork $proof): bool
    {
        return $actor->isSuperAdmin();
    }
}
