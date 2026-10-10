<?php

namespace App\Models;

use App\Enums\OrgRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

class OrganizationUser extends Pivot
{
    public $incrementing = true;

    protected $table = 'organization_users';

    /** @var list<string> */
    protected $fillable = ['organization_id', 'user_id', 'org_role', 'designation'];

    protected function casts(): array
    {
        return ['org_role' => OrgRole::class];
    }
}
