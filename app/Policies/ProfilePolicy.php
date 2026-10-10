<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Profile;
use App\Models\User;

class ProfilePolicy
{
    public function before(?User $actor): ?bool
    {
        return $actor?->status === UserStatus::Suspended ? false : null;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin() && ($actor->can('contributors.view') || $actor->can('clients.view'));
    }

    /** Guests may read public profiles of active contributors only. */
    public function view(?User $actor, Profile $profile): bool
    {
        $profile->loadMissing('user');

        if ($actor !== null && ($actor->id === $profile->user_id || $this->adminCan($actor, $profile, 'view'))) {
            return true;
        }

        return $profile->is_public
            && $profile->user?->role === UserRole::Contributor
            && $profile->user->status === UserStatus::Active;
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin() && ($actor->can('contributors.create') || $actor->can('clients.create'));
    }

    public function update(User $actor, Profile $profile): bool
    {
        return $actor->id === $profile->user_id || $this->adminCan($actor, $profile, 'update');
    }

    public function delete(User $actor, Profile $profile): bool
    {
        return $this->adminCan($actor, $profile, 'delete');
    }

    private function adminCan(User $actor, Profile $profile, string $ability): bool
    {
        if (! $actor->isAdmin()) {
            return false;
        }

        $profile->loadMissing('user');

        return match ($profile->user?->role) {
            UserRole::Contributor => $actor->can("contributors.{$ability}"),
            UserRole::Client => $actor->can("clients.{$ability}"),
            default => $actor->can("admin_users.{$ability}"),
        };
    }
}
