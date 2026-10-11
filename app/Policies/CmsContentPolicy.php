<?php

namespace App\Policies;

/** Spec section 5, "Website CMS": community_manager CRUD and super_admin all. Used by every Website content model. */
class CmsContentPolicy extends PermissionPolicy
{
    protected function resource(): string
    {
        return 'cms';
    }
}
