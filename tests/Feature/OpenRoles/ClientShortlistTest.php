<?php

use App\Enums\AdminRole;
use App\Enums\ApplicationStatus;
use App\Enums\FeatureFlag;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\SowStatus;
use App\Filament\Admin\Resources\Projects\Pages\CreateProject;
use App\Livewire\Client\Projects\ProjectShow;
use App\Models\Application;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Skill;
use App\Models\Sow;
use App\Services\FeatureFlags\FeatureFlags;
use App\Services\Projects\ClientShortlist;
use Livewire\Livewire;

beforeEach(function () {
    $this->org = Organization::factory()->verified()->create();
    $this->owner = clientUser($this->org);
    $this->project = Project::factory()->status(ProjectStatus::Staffing)->create([
        'organization_id' => $this->org->id,
        'client_can_view_shortlist' => true,
    ]);
    Sow::factory()->status(SowStatus::Accepted)->create(['project_id' => $this->project->id]);
    $this->role = OpenRole::factory()->published()->create(['project_id' => $this->project->id, 'title' => 'Research analyst']);

    $this->candidate = contributorIn(OnboardingState::Active, ['headline' => 'Market researcher'], ['name' => 'Anita Verma', 'email' => 'anita.secret@example.test']);
    $this->candidate->skills()->attach(Skill::factory()->create(['name' => 'Desk Research']), ['level' => 'expert']);
});

function addShortlisted(?Application $application = null, ApplicationStatus $status = ApplicationStatus::Shortlisted): Application
{
    return Application::factory()->status($status)->create([
        'open_role_id' => test()->role->id,
        'user_id' => test()->candidate->id,
        'motivation' => 'Private motivation text',
        'expected_amount' => 98765,
        'admin_note' => 'Internal admin note',
    ]);
}

function shortlistPage()
{
    return Livewire::actingAs(test()->owner)->test(ProjectShow::class, ['project' => test()->project]);
}

it('shows a shortlisted applicant by first name, role, headline and skills only', function () {
    addShortlisted();

    $html = shortlistPage()->assertSee('Shortlisted for your project')
        ->assertSee('Anita')->assertSee('Applied for Research analyst')->assertSee('Market researcher')->assertSee('Desk Research')
        ->html();

    foreach (['Verma', 'anita.secret', 'Private motivation', '98765', '98,765', 'Internal admin note'] as $secret) {
        expect($html)->not->toContain($secret);
    }
});

it('shows offered applicants too, and nobody in any other state', function (ApplicationStatus $status, bool $shown) {
    addShortlisted(status: $status);

    $shown ? shortlistPage()->assertSee('Anita') : shortlistPage()->assertDontSee('Anita')->assertDontSee('Shortlisted for your project');
})->with([
    [ApplicationStatus::Submitted, false],
    [ApplicationStatus::Shortlisted, true],
    [ApplicationStatus::Offered, true],
    [ApplicationStatus::Accepted, false],
    [ApplicationStatus::Rejected, false],
    [ApplicationStatus::Withdrawn, false],
    [ApplicationStatus::Declined, false],
]);

it('stays hidden unless the project switch is on', function () {
    addShortlisted();
    $this->project->update(['client_can_view_shortlist' => false]);

    shortlistPage()->assertDontSee('Anita')->assertDontSee('Shortlisted for your project');
});

it('stays hidden until the SOW is accepted', function () {
    addShortlisted();
    Sow::query()->where('project_id', $this->project->id)->update(['status' => SowStatus::Sent]);

    shortlistPage()->assertDontSee('Anita');
});

it('stays hidden when the team is hidden from the client', function () {
    addShortlisted();
    $this->project->update(['hide_team' => true]);

    shortlistPage()->assertDontSee('Anita');
});

it('only lists applicants to roles on this project', function () {
    addShortlisted();
    $otherProject = Project::factory()->status(ProjectStatus::Staffing)->create(['organization_id' => $this->org->id, 'client_can_view_shortlist' => true]);
    $otherRole = OpenRole::factory()->published()->create(['project_id' => $otherProject->id, 'title' => 'Other project role']);
    $stranger = contributorIn(OnboardingState::Active, user: ['name' => 'Stranger Person']);
    Application::factory()->status(ApplicationStatus::Shortlisted)->create(['open_role_id' => $otherRole->id, 'user_id' => $stranger->id]);
    $community = OpenRole::factory()->published()->create(['project_id' => null]);
    Application::factory()->status(ApplicationStatus::Shortlisted)->create(['open_role_id' => $community->id, 'user_id' => contributorIn(OnboardingState::Active, user: ['name' => 'Community Person'])->id]);

    shortlistPage()->assertSee('Anita')->assertDontSee('Stranger')->assertDontSee('Community')->assertDontSee('Other project role');
});

it('is read-only for every org role, and never shown to another organization', function () {
    addShortlisted();

    foreach ([OrgRole::Manager, OrgRole::Viewer] as $role) {
        Livewire::actingAs(clientUser($this->org, $role))->test(ProjectShow::class, ['project' => $this->project])->assertSee('Anita');
    }

    $outsider = clientUser();
    asUser($outsider)->get(route('client.projects.show', $this->project))->assertForbidden();
});

it('never exposes the shortlist to contributors or on the contributor and public pages', function () {
    addShortlisted();

    asUser($this->candidate)->get(route('client.projects.show', $this->project))->assertForbidden();
    $this->get('/opportunities')->assertDontSee('Anita');
});

it('is empty in the service when any condition fails', function () {
    addShortlisted();
    $service = app(ClientShortlist::class);

    expect($service->entries($this->project))->toHaveCount(1);

    foreach ([['client_can_view_shortlist' => false], ['hide_team' => true]] as $change) {
        $project = $this->project->replicate();
        $project->forceFill([...$this->project->getAttributes(), ...$change]);

        expect($service->entries($project))->toBeEmpty();
    }
});

it('issues the same number of queries for 2 candidates as for 8', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ClientShortlist::class)->entries($this->project->fresh());

        return count(DB::getQueryLog());
    };

    $add = function (int $n): void {
        foreach (range(1, $n) as $i) {
            $user = contributorIn(OnboardingState::Active);
            $user->skills()->attach(Skill::factory()->create(), ['level' => 'advanced']);
            Application::factory()->status(ApplicationStatus::Shortlisted)->create(['open_role_id' => $this->role->id, 'user_id' => $user->id]);
        }
    };

    $add(2);
    $few = $count();
    $add(6);

    expect($count())->toBe($few);
});

it('starts a new project from the global shortlist flag', function () {
    $dm = adminUser(AdminRole::DeliveryManager);

    Livewire::actingAs($dm)->test(CreateProject::class)->assertFormSet(['client_can_view_shortlist' => false]);

    app(FeatureFlags::class)->set(FeatureFlag::ClientCanViewShortlist, true);
    app(FeatureFlags::class)->flush();

    Livewire::actingAs($dm)->test(CreateProject::class)->assertFormSet(['client_can_view_shortlist' => true]);
});
