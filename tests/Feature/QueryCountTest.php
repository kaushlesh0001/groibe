<?php

use App\Enums\AdminRole;
use App\Enums\BriefStatus;
use App\Enums\OnboardingState;
use App\Enums\ProjectStatus;
use App\Filament\Admin\Resources\Briefs\Pages\ListBriefs;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\Pages\ListProjects;
use App\Filament\Admin\Resources\Projects\Pages\ProjectWorkspace;
use App\Filament\Admin\Resources\Projects\RelationManagers\AssignmentsRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\MilestonesRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\SowsRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\TasksRelationManager;
use App\Filament\Admin\Resources\Submissions\Pages\ListSubmissions;
use App\Livewire\Contributor\Work\MyWork;
use App\Livewire\Contributor\Work\ProjectBoard;
use App\Livewire\Contributor\Work\TaskDetail;
use App\Models\Assignment;
use App\Models\Brief;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Sow;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskComment;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Runs the callback and returns how many queries it issued. */
function queries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

beforeEach(function () {
    publishAgreements();
    $this->org = Organization::factory()->verified()->create();
    $this->owner = acceptClientMsa($this->org);
    $this->dm = adminUser(AdminRole::DeliveryManager);
});

function seedRows(int $n): void
{
    foreach (range(1, $n) as $i) {
        $type = ProjectType::factory()->create();
        Brief::factory()->status(BriefStatus::cases()[$i % 7])->create([
            'organization_id' => test()->org->id, 'project_type_id' => $type->id, 'assigned_admin_id' => adminUser(AdminRole::DeliveryManager)->id,
        ]);
        Project::factory()->status(ProjectStatus::SowSent)->create([
            'organization_id' => test()->org->id, 'project_type_id' => $type->id, 'delivery_manager_id' => adminUser(AdminRole::DeliveryManager)->id,
        ]);
    }
}

it('issues the same number of queries for 3 rows as for 10 on every new list page', function () {
    $pages = [
        'client briefs' => fn () => $this->actingAs($this->owner)->get('/app/client/briefs')->assertOk(),
        'client projects' => fn () => $this->actingAs($this->owner)->get('/app/client/projects')->assertOk(),
        'client dashboard' => fn () => $this->actingAs($this->owner)->get('/app/client')->assertOk(),
        'admin briefs' => fn () => Livewire::actingAs($this->dm)->test(ListBriefs::class),
        'admin projects' => fn () => Livewire::actingAs($this->dm)->test(ListProjects::class),
    ];

    seedRows(3);
    collect($pages)->each(fn ($page) => $page()); // warm caches (permissions, session) so only row-driven queries are compared
    $small = collect($pages)->map(fn ($page) => queries($page))->all();

    seedRows(7);
    $large = collect($pages)->map(fn ($page) => queries($page))->all();

    expect($large)->toBe($small);
});

it('does not query more per task or milestone in the project work plan screens', function () {
    $project = Project::factory()->create(['organization_id' => $this->org->id]);
    $seat = Assignment::factory()->active()->create(['project_id' => $project->id]);
    $addRows = function (int $milestones) use ($project, $seat): void {
        Milestone::factory()->count($milestones)->create(['project_id' => $project->id])
            ->each(fn (Milestone $m) => Task::factory()->count(2)->create(['milestone_id' => $m->id, 'assignment_id' => $seat->id]));
    };
    $screens = [
        'tasks panel' => fn () => Livewire::actingAs($this->dm)->test(TasksRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class]),
        'milestones panel' => fn () => Livewire::actingAs($this->dm)->test(MilestonesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class]),
        'workspace' => fn () => Livewire::actingAs($this->dm)->test(ProjectWorkspace::class, ['record' => $project->getRouteKey()]),
    ];

    $addRows(2);
    $small = [];
    foreach ($screens as $name => $open) {
        $open(); // warm caches
        $small[$name] = queries($open);
    }

    $addRows(5);

    foreach ($screens as $name => $open) {
        expect(queries($open))->toBe($small[$name], $name);
    }
});

it('does not query more per row on the contributor work pages', function () {
    $me = contributorIn(OnboardingState::Active);
    $project = Project::factory()->status(ProjectStatus::Active)->create(['organization_id' => $this->org->id]);
    $seat = Assignment::factory()->active()->create(['project_id' => $project->id, 'user_id' => $me->id]);
    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    $task = Task::factory()->create(['milestone_id' => $milestone->id, 'assignment_id' => $seat->id]);
    $addRows = function (int $n) use ($me, $milestone, $seat, $task): void {
        Assignment::factory()->count($n)->create(['user_id' => $me->id]);
        Task::factory()->count($n)->create(['milestone_id' => $milestone->id, 'assignment_id' => $seat->id]);
        foreach (range(1, $n) as $i) {
            TaskComment::factory()->create(['task_id' => $task->id]);
            Submission::factory()->create(['task_id' => $task->id, 'assignment_id' => $seat->id, 'version' => $task->submissions()->count() + 1]);
        }
    };
    $screens = [
        'my work' => fn () => Livewire::actingAs($me)->test(MyWork::class),
        'board' => fn () => Livewire::actingAs($me)->test(ProjectBoard::class, ['project' => $project]),
        'task' => fn () => Livewire::actingAs($me)->test(TaskDetail::class, ['task' => $task]),
    ];

    $addRows(2);
    $small = [];
    foreach ($screens as $name => $open) {
        $open(); // warm caches
        $small[$name] = queries($open);
    }

    $addRows(6);

    foreach ($screens as $name => $open) {
        expect(queries($open))->toBe($small[$name], $name);
    }
});

it('does not query more per submission row in the QA queue', function () {
    $project = Project::factory()->status(ProjectStatus::Active)->create(['organization_id' => $this->org->id]);
    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    $seat = Assignment::factory()->active()->create(['project_id' => $project->id]);
    $addRows = fn (int $n) => Submission::factory()->count($n)->create([
        'task_id' => fn () => Task::factory()->create(['milestone_id' => $milestone->id, 'assignment_id' => $seat->id])->id,
        'assignment_id' => $seat->id,
    ]);
    $open = fn () => Livewire::actingAs($this->dm)->test(ListSubmissions::class);

    $addRows(2);
    $open(); // warm caches
    $small = queries($open);

    $addRows(7);

    expect(queries($open))->toBe($small);
});

it('does not query more per assignment row in the project team panel', function () {
    $project = Project::factory()->create(['organization_id' => $this->org->id]);
    $open = fn () => Livewire::actingAs($this->dm)->test(AssignmentsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class]);

    Assignment::factory()->count(2)->create(['project_id' => $project->id]);
    $open(); // warm caches
    $small = queries($open);

    Assignment::factory()->count(7)->create(['project_id' => $project->id]);

    expect(queries($open))->toBe($small);
});

it('does not query more per SOW row in the project relation manager', function () {
    $project = Project::factory()->create(['organization_id' => $this->org->id]);
    $open = fn () => Livewire::actingAs($this->dm)->test(SowsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class]);

    foreach (range(1, 2) as $v) {
        Sow::factory()->create(['project_id' => $project->id, 'version' => $v, 'accepted_by' => $this->owner->id]);
    }
    $open(); // warm caches
    $small = queries($open);

    foreach (range(3, 9) as $v) {
        Sow::factory()->create(['project_id' => $project->id, 'version' => $v, 'accepted_by' => $this->owner->id]);
    }

    expect(queries($open))->toBe($small);
});

it('keeps the SOW relation and every project action away from roles without SOW or project write access', function () {
    $project = Project::factory()->status(ProjectStatus::Staffing)->create(['organization_id' => $this->org->id]);
    $sow = Sow::factory()->create(['project_id' => $project->id]);

    foreach ([AdminRole::CommunityManager, AdminRole::FinanceOps] as $role) {
        asUser(adminUser($role))->get("/admin/projects/{$project->getRouteKey()}/edit")->assertForbidden();
    }

    $manager = fn (AdminRole $role) => Livewire::actingAs(adminUser($role))
        ->test(SowsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class]);

    // Finance may read SOWs but never change them.
    $finance = $manager(AdminRole::FinanceOps);
    foreach (['newSow'] as $name) {
        $finance->assertActionHidden(TestAction::make($name)->table());
    }
    foreach (['editSow', 'sendSow'] as $name) {
        $finance->assertActionHidden(TestAction::make($name)->table($sow));
    }
    $finance->assertActionVisible(TestAction::make('pdf')->table($sow));
});
