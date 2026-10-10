<?php

namespace App\Policies;

/** Spec section 5: settings and flags are super_admin only. */
class SettingPolicy extends PermissionPolicy
{
    protected function resource(): string
    {
        return 'settings';
    }
}
