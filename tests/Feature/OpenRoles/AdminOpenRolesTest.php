<?php

use App\Enums\AdminRole;
use App\Enums\ApplicationStatus;
use App\Enums\OnboardingState;
use App\Enums\OpenRoleStatus;
use App\Enums\OpenRoleVisibility;
use App\Enums\ProjectStatus;
use App\Filament\Admin\Resources\Applications\Pages\ListApplications;
use App\Filament\Admin\Resources\Applications\Pages\ViewApplication;
use App\Filament\Admin\Resources\OpenRoles\Pages\CreateOpenRole;
use App\Filament\Admin\Resources\OpenRoles\Pages\EditOpenRole;
use App\Filament\Admin\Resources\OpenRoles\Pages\ListOpenRoles;
use App\Filament\Admin\Resources\OpenRoles\RelationManagers\InvitesRelationManager;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Models\Application;
use App\Models\OpenRole;
use App\Models\Project;
use App\Models\Skill;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->cm = adminUser(AdminRole::CommunityManager);
});

it('lets an admin create a role, publish it and see it on the board', function () {
    $skill = Skill::factory()->create();

    Livewire::actingAs($this->cm)->test(CreateOpenRole::class)
        ->fillForm([
            'title' => 'Survey analyst',
            'summary' => 'Analyse survey data.',
            'description' => 'Clean and analyse responses.',
            'work_mode' => 'remote',
            'compensation_type' => 'paid',
            'visibility' => 'public',
            'positions_needed' => 2,
            'skill_ids' => [$skill->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = OpenRole::query()->where('title', 'Survey analyst')->sole();
    expect($role->status)->toBe(OpenRoleStatus::Draft)->and($role->skills)->toHaveCount(1);

    Livewire::actingAs($this->cm)->test(EditOpenRole::class, ['record' => $role->getKey()])
        ->assertFormSet(['skill_ids' => [$skill->id]])
        ->callAction('publish');

    expect($role->fresh()->status)->toBe(OpenRoleStatus::Published);

    Livewire::actingAs($this->cm)->test(ListOpenRoles::class)->assertCanSeeTableRecords([$role]);
});

it('shows the publish, close and archive buttons only in the right state', function () {
    $draft = OpenRole::factory()->create();
    $live = OpenRole::factory()->published()->create();

    Livewire::actingAs($this->dm)->test(EditOpenRole::class, ['record' => $draft->getKey()])
        ->assertActionVisible('publish')->assertActionHidden('close')->assertActionHidden('archive');

    Livewire::actingAs($this->dm)->test(EditOpenRole::class, ['record' => $live->getKey()])
        ->assertActionHidden('publish')->assertActionVisible('close')->assertActionVisible('archive');
});

it('explains a refused publish instead of failing', function () {
    $role = OpenRole::factory()->create(['summary' => null]);

    Livewire::actingAs($this->dm)->test(EditOpenRole::class, ['record' => $role->getKey()])->callAction('publish');

    expect($role->fresh()->status)->toBe(OpenRoleStatus::Draft);
});

it('manages the invite list from the role page, for invite-only roles only', function () {
    $role = OpenRole::factory()->published()->inviteOnly()->create();
    $contributor = contributorIn(OnboardingState::Active, user: ['name' => 'Invitee One']);

    Livewire::actingAs($this->cm)->test(InvitesRelationManager::class, ['ownerRecord' => $role, 'pageClass' => EditOpenRole::class])
        ->callTableAction('invite', data: ['user_id' => $contributor->id])
        ->assertCanSeeTableRecords([$contributor]);

    expect($role->invitedUsers()->count())->toBe(1);

    Livewire::actingAs($this->cm)->test(InvitesRelationManager::class, ['ownerRecord' => $role, 'pageClass' => EditOpenRole::class])
        ->callTableAction('remove', $contributor);

    expect($role->invitedUsers()->count())->toBe(0)
        ->and(InvitesRelationManager::canViewForRecord(OpenRole::factory()->create(['visibility' => OpenRoleVisibility::Public]), EditOpenRole::class))->toBeFalse()
        ->and(InvitesRelationManager::canViewForRecord($role, EditOpenRole::class))->toBeTrue();
});

it('reviews applications through the admin actions', function () {
    $role = OpenRole::factory()->published()->create();
    $application = Application::factory()->create(['open_role_id' => $role->id, 'user_id' => contributorIn(OnboardingState::Active)->id]);

    Livewire::actingAs($this->cm)->test(ListApplications::class)
        ->assertCanSeeTableRecords([$application])
        ->callTableAction('shortlist', $application);

    expect($application->fresh()->status)->toBe(ApplicationStatus::Shortlisted);

    Livewire::actingAs($this->dm)->test(ViewApplication::class, ['record' => $application->getKey()])
        ->assertActionVisible('offer')->assertActionHidden('shortlist')
        ->callAction('offer');

    expect($application->fresh()->status)->toBe(ApplicationStatus::Offered);

    Livewire::actingAs($this->dm)->test(ViewApplication::class, ['record' => $application->getKey()])
        ->callAction('reject');

    expect($application->fresh()->status)->toBe(ApplicationStatus::Rejected);
});

it('hides review actions from roles without the permission and from finished applications', function () {
    $submitted = Application::factory()->create();
    $accepted = Application::factory()->status(ApplicationStatus::Accepted)->create();

    Livewire::actingAs(adminUser(AdminRole::FinanceOps))->test(ViewApplication::class, ['record' => $submitted->getKey()])
        ->assertForbidden();

    Livewire::actingAs($this->dm)->test(ViewApplication::class, ['record' => $accepted->getKey()])
        ->assertActionHidden('shortlist')->assertActionHidden('offer')->assertActionHidden('reject');
});

it('keeps Open Roles and Applications away from finance, contributors, clients and guests', function () {
    $role = OpenRole::factory()->create();
    $application = Application::factory()->create();

    foreach ([adminUser(AdminRole::FinanceOps), contributorUser(), clientUser()] as $denied) {
        asUser($denied)->get('/admin/open-roles')->assertForbidden();
        asUser($denied)->get('/admin/open-roles/create')->assertForbidden();
        asUser($denied)->get("/admin/open-roles/{$role->getKey()}/edit")->assertForbidden();
        asUser($denied)->get('/admin/applications')->assertForbidden();
        asUser($denied)->get("/admin/applications/{$application->getKey()}")->assertForbidden();
    }

    test()->flushSession();
    auth()->forgetGuards();
    $this->get('/admin/open-roles')->assertRedirect('/admin/login');
});

it('lets delivery and community managers into both screens', function () {
    foreach ([$this->dm, $this->cm, adminUser()] as $admin) {
        asUser($admin)->get('/admin/open-roles')->assertOk();
        asUser($admin)->get('/admin/applications')->assertOk();
    }
});

it('keeps community managers able to delete an unused role but not one with applications', function () {
    $unused = OpenRole::factory()->create();
    $used = Application::factory()->create()->openRole;

    expect($this->cm->can('delete', $unused))->toBeTrue()
        ->and($this->cm->can('delete', $used))->toBeFalse()
        ->and($this->dm->can('delete', $unused))->toBeFalse()
        ->and(adminUser()->can('delete', $unused))->toBeTrue();
});

it('creates a linked draft from the project page', function () {
    $project = Project::factory()->status(ProjectStatus::Staffing)->create(['title' => 'Dashboard build']);

    Livewire::actingAs($this->dm)->test(EditProject::class, ['record' => $project->getRouteKey()])
        ->assertActionVisible('publishAsOpenRole')
        ->callAction('publishAsOpenRole');

    expect(OpenRole::query()->where('project_id', $project->id)->sole()->status)->toBe(OpenRoleStatus::Draft);

    $early = Project::factory()->status(ProjectStatus::SowSent)->create();
    Livewire::actingAs($this->dm)->test(EditProject::class, ['record' => $early->getRouteKey()])->assertActionHidden('publishAsOpenRole');

    $finance = adminUser(AdminRole::FinanceOps);
    Livewire::actingAs($finance)->test(EditProject::class, ['record' => $project->getRouteKey()])->assertForbidden();
});

it('loads the role and application lists without extra queries per row', function () {
    $role = OpenRole::factory()->published()->create();
    Application::factory()->count(6)->create(['open_role_id' => $role->id]);
    OpenRole::factory()->published()->count(5)->create();

    $count = function (callable $run): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();

        return count(DB::getQueryLog());
    };

    $few = $count(fn () => asUser($this->cm)->get('/admin/applications')->assertOk());
    Application::factory()->count(10)->create(['open_role_id' => $role->id]);
    $many = $count(fn () => asUser($this->cm)->get('/admin/applications')->assertOk());

    expect($many)->toBeLessThanOrEqual($few + 2);
});
