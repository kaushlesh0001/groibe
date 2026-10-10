<?php

use App\Actions\Agreements\AcceptClientMsa;
use App\Enums\AdminRole;
use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AgreementTemplateSeeder;
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

/** Publish active Client MSA and SOW templates, as the AgreementTemplateSeeder does. */
function publishAgreements(): void
{
    test()->seed(AgreementTemplateSeeder::class);
}

/** Make the organization satisfy the Client MSA gate: its owner accepts the current version. */
function acceptClientMsa(Organization $organization): User
{
    $owner = $organization->users()->wherePivot('org_role', OrgRole::Owner->value)->first()
        ?? clientUser($organization, OrgRole::Owner);

    app(AcceptClientMsa::class)($owner, $organization);

    return $owner;
}

/**
 * Sign in as another user mid-test. The session is cleared first so Filament's AuthenticateSession
 * middleware does not treat the previous user's password hash as a mismatch and log the new user out.
 */
function asUser(User $user): TestCase
{
    test()->flushSession();
    auth()->forgetGuards();

    return test()->actingAs($user);
}

/** Create a client user attached to a (new or given) organization with the given org role. */
function clientUser(?Organization $organization = null, OrgRole $orgRole = OrgRole::Owner): User
{
    $user = User::factory()->client()->create();
    $organization ??= Organization::factory()->verified()->create();
    $organization->users()->attach($user->id, ['org_role' => $orgRole->value]);

    return $user;
}
