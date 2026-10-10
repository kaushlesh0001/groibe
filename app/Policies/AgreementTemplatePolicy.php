<?php

namespace App\Policies;

use App\Models\AgreementTemplate;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/** Spec 5 "Agreements": admins read, super_admin writes. Acceptance is a separate action, not a policy on the template. */
class AgreementTemplatePolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $this->adminCan($actor, 'agreements.view');
    }

    public function view(User $actor, AgreementTemplate $template): bool
    {
        return $this->adminCan($actor, 'agreements.view');
    }

    public function create(User $actor): bool
    {
        return $this->adminCan($actor, 'agreements.create');
    }

    public function update(User $actor, AgreementTemplate $template): bool
    {
        return $this->adminCan($actor, 'agreements.update');
    }

    public function delete(User $actor, AgreementTemplate $template): bool
    {
        return $this->adminCan($actor, 'agreements.delete') && ! $template->acceptances()->exists();
    }
}
