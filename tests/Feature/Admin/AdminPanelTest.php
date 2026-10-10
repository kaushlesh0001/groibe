<?php

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Activities\ActivityResource;
use App\Filament\Admin\Resources\Activities\Pages\ListActivities;
use App\Filament\Admin\Resources\Categories\CategoryResource;
use App\Filament\Admin\Resources\FeatureFlags\FeatureFlagResource;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Filament\Admin\Resources\Organizations\Pages\CreateOrganization;
use App\Filament\Admin\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Admin\Resources\Organizations\RelationManagers\UsersRelationManager;
use App\Filament\Admin\Resources\ProjectTypes\ProjectTypeResource;
use App\Filament\Admin\Resources\Settings\SettingResource;
use App\Filament\Admin\Resources\Skills\SkillResource;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InviteNotification;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('renders every resource index for a super admin', function (string $resource) {
    $this->actingAs(adminUser())->get($resource::getUrl('index'))->assertOk();
})->with([
    UserResource::class,
    OrganizationResource::class,
    SkillResource::class,
    FeatureFlagResource::class,
    SettingResource::class,
    CategoryResource::class,
    ProjectTypeResource::class,
    ActivityResource::class,
]);

it('hides super-admin-only resources from other admin roles', function (string $resource) {
    $this->actingAs(adminUser(AdminRole::DeliveryManager))->get($resource::getUrl('index'))->assertForbidden();
})->with(['settings' => [SettingResource::class], 'flags' => [FeatureFlagResource::class]]);

it('opens skills, categories but not project types for a community manager', function () {
    $this->actingAs(adminUser(AdminRole::CommunityManager));
    $this->get(SkillResource::getUrl('index'))->assertOk();
    $this->get(CategoryResource::getUrl('index'))->assertOk();
    $this->get(ProjectTypeResource::getUrl('index'))->assertForbidden();
});

it('opens skills and project types for a delivery manager', function () {
    $this->actingAs(adminUser(AdminRole::DeliveryManager));
    $this->get(SkillResource::getUrl('index'))->assertOk();
    $this->get(ProjectTypeResource::getUrl('index'))->assertOk();
});

it('lets a delivery manager open users and organizations', function () {
    $this->actingAs(adminUser(AdminRole::DeliveryManager));

    $this->get(UserResource::getUrl('index'))->assertOk();
    $this->get(OrganizationResource::getUrl('index'))->assertOk();
});

it('keeps admin accounts out of the user list for non-super admins', function () {
    $super = adminUser();
    $contributor = contributorUser();

    Livewire::actingAs(adminUser(AdminRole::DeliveryManager))
        ->test(ListUsers::class)
        ->assertCanSeeTableRecords([$contributor])
        ->assertCanNotSeeTableRecords([$super]);

    Livewire::actingAs(adminUser())
        ->test(ListUsers::class)
        ->assertCanSeeTableRecords([$super, $contributor]);
});

it('prevents privilege escalation through the role field', function () {
    $dm = adminUser(AdminRole::DeliveryManager);
    $contributor = contributorUser();

    Livewire::actingAs($dm)
        ->test(EditUser::class, ['record' => $contributor->getRouteKey()])
        ->fillForm(['role' => UserRole::Admin->value])
        ->call('save');

    expect($contributor->fresh()->role)->toBe(UserRole::Contributor);
});

it('creates a client user and organization by hand, links them and sends an invite', function () {
    Notification::fake();
    $this->actingAs(adminUser());

    Livewire::test(CreateOrganization::class)
        ->fillForm(['name' => 'Acme Foods', 'slug' => 'acme-foods', 'status' => 'verified'])
        ->call('create')
        ->assertHasNoFormErrors();

    $org = Organization::query()->where('slug', 'acme-foods')->firstOrFail();
    expect($org->created_by)->not->toBeNull();

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Meera Iyer', 'email' => 'meera@acme.test', 'role' => 'client', 'status' => 'pending'])
        ->call('create')
        ->assertHasNoFormErrors();

    $client = User::query()->where('email', 'meera@acme.test')->firstOrFail();
    expect($client->password)->toBeNull()
        ->and($client->profile)->not->toBeNull()
        ->and($client->hasRole('client'))->toBeTrue();

    Livewire::test(UsersRelationManager::class, ['ownerRecord' => $org, 'pageClass' => EditOrganization::class])
        ->callAction(TestAction::make('attach')->table(), ['recordId' => $client->id, 'org_role' => 'owner'])
        ->assertHasNoFormErrors();

    expect($org->users()->whereKey($client->id)->exists())->toBeTrue();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('sendInvite')->table($client));

    Notification::assertSentTo($client, InviteNotification::class);
});

it('shows only own audit entries to a delivery manager and all to a super admin', function () {
    $super = adminUser();
    $dm = adminUser(AdminRole::DeliveryManager);
    $mine = activity()->causedBy($dm)->log('dm action');
    $theirs = activity()->causedBy($super)->log('super action');

    Livewire::actingAs($dm)->test(ListActivities::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    Livewire::actingAs($super)->test(ListActivities::class)
        ->assertCanSeeTableRecords([$mine, $theirs]);
});

it('records admin writes in the activity log', function () {
    $this->actingAs(adminUser());

    $org = Organization::factory()->create(['name' => 'Before']);
    $org->update(['name' => 'After']);

    $entry = Activity::query()
        ->where('subject_type', Organization::class)->where('event', 'updated')->latest('id')->firstOrFail();

    expect($entry->causer_id)->not->toBeNull()
        ->and($entry->properties->toArray())->not->toBeEmpty();
});
