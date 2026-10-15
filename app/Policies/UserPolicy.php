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

    /**
     * Creating an account of a given role needs that role's own create permission (spec 5): a delivery manager may create client
     * users but not contributors, a community manager contributors but not clients, and only a super admin admins.
     */
    public function createRole(User $actor, UserRole $role): bool
    {
        return $actor->isAdmin() && $actor->can(match ($role) {
            UserRole::Contributor => 'contributors.create',
            UserRole::Client => 'clients.create',
            UserRole::Admin => 'admin_users.create',
        });
    }

    /**
     * Who may set the sign-in details of someone else's account: email, password, status and role. Only holders of
     * `admin_users.update` (the super admin). Anyone else could otherwise sign in as the person, which is what impersonation
     * is for, with its log (spec 4). Staff with update rights still change names and time zones, and move a contributor's
     * state through the contributor Actions.
     */
    public function manageCredentials(User $actor): bool
    {
        return $actor->isAdmin() && $actor->can('admin_users.update');
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
