<?php

use App\Enums\AdminRole;
use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Enums\OnboardingState;
use App\Enums\OpenRoleStatus;
use App\Enums\ProjectStatus;
use App\Filament\Admin\Resources\Applications\Pages\ViewApplication;
use App\Filament\Admin\Resources\OpenRoles\Pages\CreateOpenRole;
use App\Filament\Admin\Resources\OpenRoles\Pages\EditOpenRole;
use App\Livewire\Contributor\Discover\Index;
use App\Livewire\Contributor\Discover\Show;
use App\Livewire\Contributor\MyApplications;
use App\Livewire\Contributor\Work\MyWork;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\OpenRole;
use App\Models\Project;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('runs the Done-when flow: publish, apply, shortlist, offer, accept, and appear as an active assignee', function () {
    Notification::fake();
    $cm = adminUser(AdminRole::CommunityManager);
    $dm = adminUser(AdminRole::DeliveryManager);
    $contributor = contributorIn(OnboardingState::Active);
    $project = Project::factory()->status(ProjectStatus::Staffing)->create(['delivery_manager_id' => $dm->id]);

    // Admin publishes a role linked to the project.
    Livewire::actingAs($cm)->test(CreateOpenRole::class)->fillForm([
        'title' => 'Research analyst', 'summary' => 'Analyse the market.', 'description' => 'Desk research.',
        'work_mode' => 'remote', 'compensation_type' => 'paid', 'visibility' => 'members_only',
        'positions_needed' => 1, 'project_id' => $project->id,
    ])->call('create')->assertHasNoFormErrors();
    $role = OpenRole::query()->sole();
    Livewire::actingAs($cm)->test(EditOpenRole::class, ['record' => $role->getKey()])->callAction('publish');
    expect($role->fresh()->status)->toBe(OpenRoleStatus::Published);

    // The contributor finds it and applies.
    Livewire::actingAs($contributor)->test(Index::class)->assertSee('Research analyst');
    Livewire::actingAs($contributor)->test(Show::class, ['slug' => $role->slug])
        ->set('form.motivation', 'I have done this kind of research many times.')->call('apply')->assertHasNoErrors();
    $application = Application::query()->sole();

    // Admin shortlists and offers.
    Livewire::actingAs($cm)->test(ViewApplication::class, ['record' => $application->getKey()])->callAction('shortlist');
    Livewire::actingAs($dm)->test(ViewApplication::class, ['record' => $application->getKey()])->callAction('offer');
    expect($application->fresh()->status)->toBe(ApplicationStatus::Offered);

    // The contributor accepts and is an active assignee who can see the project in My Work.
    Livewire::actingAs($contributor)->test(MyApplications::class)->call('accept', $application->id);

    expect($application->fresh()->status)->toBe(ApplicationStatus::Accepted)
        ->and($role->fresh()->status)->toBe(OpenRoleStatus::Filled)
        ->and(Assignment::query()->where('project_id', $project->id)->sole())
        ->user_id->toBe($contributor->id)->status->toBe(AssignmentStatus::Active);

    Livewire::actingAs($contributor)->test(MyWork::class)->assertSee($project->title);
});

it('ignores a bad or stale filter on Discover instead of failing, and still applies the good ones', function () {
    $contributor = contributorIn(OnboardingState::Active);
    OpenRole::factory()->published()->create(['title' => 'Visible role']);

    foreach (['work_mode=bogus', 'compensation=zzz', 'q='.str_repeat('a', 150), 'work_mode=bogus&q=Visible'] as $query) {
        asUser($contributor)->get('/app/contributor/discover?'.$query)->assertOk();
    }

    Livewire::actingAs($contributor)->test(Index::class)
        ->set('work_mode', 'bogus')->set('q', 'Visible')->assertSee('Visible role')
        ->set('q', 'nothing like it')->assertDontSee('Visible role');
});
