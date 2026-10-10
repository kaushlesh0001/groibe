<?php

namespace App\Policies;

/** Spec section 5: settings and flags are super_admin only. */
class FeatureFlagPolicy extends PermissionPolicy
{
    protected function resource(): string
    {
        return 'feature_flags';
    }
}
