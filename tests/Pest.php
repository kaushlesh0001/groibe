<?php

use App\Enums\AdminRole;
use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => test()->seed(RolesAndPermissionsSeeder::class))
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/** Create an active admin user holding the given permission set. */
function adminUser(AdminRole $role = AdminRole::SuperAdmin): User
{
    return User::factory()->admin($role)->create();
}

function contributorUser(): User
{
    return User::factory()->contributor()->create();
}

/** Create a client user attached to a (new or given) organization with the given org role. */
function clientUser(?Organization $organization = null, OrgRole $orgRole = OrgRole::Owner): User
{
    $user = User::factory()->client()->create();
    $organization ??= Organization::factory()->verified()->create();
    $organization->users()->attach($user->id, ['org_role' => $orgRole->value]);

    return $user;
}
