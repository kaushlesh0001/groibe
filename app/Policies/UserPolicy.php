<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

class UserPolicy
{
    /** Suspended accounts are denied everything. */
    public function before(User $actor): ?bool
    {
        return $actor->status === UserStatus::Suspended ? false : null;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin() && (
            $actor->can('contributors.view')
            || $actor->can('clients.view')
            || $actor->can('admin_users.view')
        );
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->is($target) || $this->adminCan($actor, $target, 'view');
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin() && (
            $actor->can('contributors.create')
            || $actor->can('clients.create')
            || $actor->can('admin_users.create')
        );
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->is($target) || $this->adminCan($actor, $target, 'update');
    }

    public function delete(User $actor, User $target): bool
    {
        return ! $actor->is($target) && $this->adminCan($actor, $target, 'delete');
    }

    public function restore(User $actor, User $target): bool
    {
        return $this->adminCan($actor, $target, 'delete');
    }

    public function forceDelete(User $actor, User $target): bool
    {
        return ! $actor->is($target) && $this->adminCan($actor, $target, 'delete');
    }

    /** Spec section 4: impersonation is super_admin only. */
    public function impersonate(User $actor, User $target): bool
    {
        return $actor->isAdmin()
            && $actor->can('users.impersonate')
            && ! $actor->is($target)
            && ! $target->isSuperAdmin()
            && $target->status === UserStatus::Active;
    }

    private function adminCan(User $actor, User $target, string $ability): bool
    {
        if (! $actor->isAdmin()) {
            return false;
        }

        $resource = match ($target->role) {
            UserRole::Contributor => 'contributors',
            UserRole::Client => 'clients',
            UserRole::Admin => 'admin_users',
        };

        return $actor->can("{$resource}.{$ability}");
    }
}
