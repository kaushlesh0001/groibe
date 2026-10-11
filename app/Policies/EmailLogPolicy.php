<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** The email log is read-only evidence and super admin only (it holds recipient addresses). Nothing can create, edit or delete a row by hand. */
class EmailLogPolicy extends PermissionPolicy
{
    protected function resource(): string
    {
        return 'email_log';
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, Model $record): bool
    {
        return false;
    }

    public function delete(User $actor, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
