<?php

namespace App\Policies;

/**
 * Covers Category and Skill (SCR-001): community_manager CRUD, delivery_manager read, super_admin all.
 * Project types have their own policy.
 */
class TaxonomyPolicy extends PermissionPolicy
{
    protected function resource(): string
    {
        return 'taxonomy';
    }
}
