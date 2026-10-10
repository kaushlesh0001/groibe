<?php

namespace App\Policies;

/** SCR-001: delivery_manager reads project types, super_admin writes them. */
class ProjectTypePolicy extends PermissionPolicy
{
    protected function resource(): string
    {
        return 'project_types';
    }
}
