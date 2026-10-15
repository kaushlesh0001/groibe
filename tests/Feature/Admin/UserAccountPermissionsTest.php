<?php

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
 * Pass 9 item 8 (review findings): spec 5 gives each admin role its own create and update rights on user accounts, and
 * impersonation, with its log, is the only way into someone else's account (spec 4). Every case here also sends the fields the form
 * hides or disables, because a request does not have to come from the form.
 */

dataset('roles that may not create what they are not given', [
    'delivery manager makes a contributor' => [AdminRole::DeliveryManager, 'contributor'],
    'delivery manager makes an admin' => [AdminRole::DeliveryManager, 'admin'],
    'community manager makes a client' => [AdminRole::CommunityManager, 'client'],
    'community manager makes an admin' => [AdminRole::CommunityManager, 'admin'],
    'finance makes a client' => [AdminRole::FinanceOps, 'client'],
    'finance makes a contributor' => [AdminRole::FinanceOps, 'contributor'],
]);

it('gives each admin role the create right of its own matrix row only', function () {
    $may = fn (AdminRole $admin, UserRole $role): bool => adminUser($admin)->can('createRole', [User::class, $role]);

    expect($may(AdminRole::DeliveryManager, UserRole::Client))->toBeTrue()
        ->and($may(AdminRole::DeliveryManager, UserRole::Contributor))->toBeFalse()
        ->and($may(AdminRole::DeliveryManager, UserRole::Admin))->toBeFalse()
        ->and($may(AdminRole::CommunityManager, UserRole::Contributor))->toBeTrue()
        ->and($may(AdminRole::CommunityManager, UserRole::Client))->toBeFalse()
        ->and($may(AdminRole::CommunityManager, UserRole::Admin))->toBeFalse()
        ->and($may(AdminRole::FinanceOps, UserRole::Client))->toBeFalse()
        ->and($may(AdminRole::FinanceOps, UserRole::Contributor))->toBeFalse()
        ->and($may(AdminRole::SuperAdmin, UserRole::Admin))->toBeTrue()
        ->and($may(AdminRole::SuperAdmin, UserRole::Client))->toBeTrue()
        ->and($may(AdminRole::SuperAdmin, UserRole::Contributor))->toBeTrue();
});

it('refuses an account of a role the admin may not create, even when the request is edited to ask for it', function (AdminRole $admin, string $role) {
    $actor = adminUser($admin);
    $before = User::query()->count();

    // The page opens for anyone who may create some role; the form's own options are not what is trusted.
    try {
        Livewire::actingAs($actor)->test(CreateUser::class)
            ->set('data.name', 'Tampered Person')->set('data.email', 'tampered@example.test')->set('data.role', $role)->set('data.timezone', 'Asia/Kolkata')
            ->call('create');
    } catch (InvalidArgumentException) {
        // A refusal by the server is a 403 with no page state; the test helper reports that as an invalid snapshot.
    }

    expect(User::query()->count())->toBe($before)->and(User::query()->where('email', 'tampered@example.test')->exists())->toBeFalse();
})->with('roles that may not create what they are not given');

it('offers each admin only the roles they may create', function (AdminRole $admin, array $offered) {
    Livewire::actingAs(adminUser($admin))->test(CreateUser::class)
        ->assertFormFieldExists('role', fn (Select $field): bool => collect(array_keys($field->getOptions()))->sort()->values()->all() === collect($offered)->sort()->values()->all());
})->with([
    'delivery manager' => [AdminRole::DeliveryManager, ['client']],
    'community manager' => [AdminRole::CommunityManager, ['contributor']],
    'super admin' => [AdminRole::SuperAdmin, ['contributor', 'client', 'admin']],
]);

it('creates a pending account with no password for a non-super admin, whatever status and password the request carries', function () {
    Livewire::actingAs(adminUser(AdminRole::DeliveryManager))->test(CreateUser::class)
        ->fillForm(['name' => 'Meera Iyer', 'email' => 'meera@acme.test', 'role' => 'client', 'timezone' => 'Asia/Kolkata'])
        ->set('data.status', 'active')->set('data.password', 'a-long-password-1')
        ->call('create')->assertHasNoFormErrors();

    $created = User::query()->where('email', 'meera@acme.test')->firstOrFail();

    expect($created->role)->toBe(UserRole::Client)
        ->and($created->status)->toBe(UserStatus::Pending)
        ->and($created->password)->toBeNull();
});

it('lets the super admin set status and password when creating an account', function () {
    Livewire::actingAs(adminUser())->test(CreateUser::class)
        ->fillForm(['name' => 'New Admin', 'email' => 'new.admin@example.test', 'role' => 'admin', 'status' => 'active', 'password' => 'a-long-password-1', 'timezone' => 'Asia/Kolkata'])
        ->call('create')->assertHasNoFormErrors();

    $created = User::query()->where('email', 'new.admin@example.test')->firstOrFail();

    expect($created->status)->toBe(UserStatus::Active)->and(Hash::check('a-long-password-1', $created->password))->toBeTrue();
});

// ---- Editing -------------------------------------------------------------------------------------------------------

it('lets staff change a name and nothing else about someone else', function (AdminRole $admin, string $target) {
    $person = User::factory()->{$target}()->create(['name' => 'Old Name', 'email' => 'old@example.test']);
    $passwordBefore = $person->password;

    Livewire::actingAs(adminUser($admin))->test(EditUser::class, ['record' => $person->getRouteKey()])
        ->set('data.name', 'New Name')->call('save')->assertHasNoFormErrors();

    $person->refresh();

    expect($person->name)->toBe('New Name')->and($person->email)->toBe('old@example.test')->and($person->password)->toBe($passwordBefore);
})->with([
    'delivery manager edits a client' => [AdminRole::DeliveryManager, 'client'],
    'delivery manager edits a contributor' => [AdminRole::DeliveryManager, 'contributor'],
    'community manager edits a contributor' => [AdminRole::CommunityManager, 'contributor'],
]);

it('ignores an edited request that sets the email, password, status or role of someone else', function (AdminRole $admin, string $target, string $roleOffered) {
    $person = User::factory()->{$target}()->create(['name' => 'Old Name', 'email' => 'old@example.test']);
    $passwordBefore = $person->password;

    // The role sent is one the actor is offered, so it passes the form's own validation and only the server-side rule stops it.
    Livewire::actingAs(adminUser($admin))->test(EditUser::class, ['record' => $person->getRouteKey()])
        ->set('data.email', 'attacker@example.test')->set('data.password', 'attacker-password-1')
        ->set('data.status', 'suspended')->set('data.role', $roleOffered)
        ->call('save');

    $person->refresh();

    expect($person->email)->toBe('old@example.test')
        ->and($person->password)->toBe($passwordBefore)
        ->and($person->status)->toBe(UserStatus::Active)
        ->and($person->role->value)->toBe($target);
})->with([
    'delivery manager, a contributor, sent the one role offered' => [AdminRole::DeliveryManager, 'contributor', 'client'],
    'delivery manager, a client' => [AdminRole::DeliveryManager, 'client', 'client'],
    'community manager, a contributor' => [AdminRole::CommunityManager, 'contributor', 'contributor'],
]);

it('hides the sign-in fields from staff who may not set them, and shows them to the super admin', function () {
    $client = User::factory()->client()->create();

    $dm = Livewire::actingAs(adminUser(AdminRole::DeliveryManager))->test(EditUser::class, ['record' => $client->getRouteKey()]);
    $dm->assertFormFieldIsHidden('password')->assertFormFieldIsHidden('status')->assertFormFieldIsDisabled('email')->assertFormFieldIsDisabled('role');

    $super = Livewire::actingAs(adminUser())->test(EditUser::class, ['record' => $client->getRouteKey()]);
    $super->assertFormFieldIsVisible('password')->assertFormFieldIsVisible('status')->assertFormFieldIsEnabled('email')->assertFormFieldIsEnabled('role');
});

it('lets the super admin change sign-in details, as before', function () {
    $client = User::factory()->client()->create(['email' => 'old@example.test']);

    Livewire::actingAs(adminUser())->test(EditUser::class, ['record' => $client->getRouteKey()])
        ->set('data.email', 'new@example.test')->set('data.status', 'suspended')->call('save')->assertHasNoFormErrors();

    expect($client->refresh()->email)->toBe('new@example.test')->and($client->status)->toBe(UserStatus::Suspended);
});
