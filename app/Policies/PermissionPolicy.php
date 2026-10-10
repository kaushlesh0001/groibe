<?php

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy for admin-managed models guarded by "<resource>.<ability>" permissions (spec section 5).
 */
abstract class PermissionPolicy
{
    /** Permission resource key, for example "taxonomy". */
    abstract protected function resource(): string;

    public function before(User $actor): ?bool
    {
        return $actor->status === UserStatus::Suspended ? false : null;
    }

    public function viewAny(User $actor): bool
    {
        return $this->allows($actor, 'view');
    }

    public function view(User $actor, Model $record): bool
    {
        return $this->allows($actor, 'view');
    }

    public function create(User $actor): bool
    {
        return $this->allows($actor, 'create');
    }

    public function update(User $actor, Model $record): bool
    {
        return $this->allows($actor, 'update');
    }

    public function delete(User $actor, Model $record): bool
    {
        return $this->allows($actor, 'delete');
    }

    public function deleteAny(User $actor): bool
    {
        return $this->allows($actor, 'delete');
    }

    protected function allows(User $actor, string $ability): bool
    {
        return $actor->isAdmin() && $actor->can($this->resource().'.'.$ability);
    }
}
