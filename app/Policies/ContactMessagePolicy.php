<?php

namespace App\Policies;

use App\Models\User;

/**
 * SCR-021: contact messages hold visitors' personal data. Community managers and super admins read and update them
 * (mark read or handled). Visitors create them through the public form, never through a policy, and only super admin
 * may delete one.
 */
class ContactMessagePolicy extends PermissionPolicy
{
    protected function resource(): string
    {
        return 'contact_messages';
    }

    public function create(User $actor): bool
    {
        return false;
    }
}
