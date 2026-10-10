<?php

use App\Enums\AdminRole;
use App\Enums\AssignmentStatus;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\SowStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Livewire\Client\Projects\ProjectShow;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sow;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\Projects\ClientTeam;
use Livewire\Livewire;

beforeEach(function () {
    $this->org = Organization::factory()->verified()->create();
    $this->owner = clientUser($this->org, OrgRole::Owner);
    $this->viewer = clientUser($this->org, OrgRole::Viewer);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['organization_id' => $this->org->id]);
    $this->sow = Sow::factory()->status(SowStatus::Accepted)->create(['project_id' => $this->project->id]);
});

/** A contributor with a real profile, seated on the project. */
function teamMember(Project $project, string $name, string $headline, string $role, AssignmentStatus $status = AssignmentStatus::Active, array $assignment = []): Assignment
{
    $user = contributorIn(OnboardingState::Active, ['headline' => $headline], ['name' => $name, 'email' => str($name)->slug().'@private.example']);

    return Assignment::factory()->status($status)->create(['project_id' => $project->id, 'user_id' => $user->id, 'role_title' => $role, ...$assignment]);
}

describe('your team', function () {
    it('shows first name, role and headline of active and completed members only', function () {
        teamMember($this->project, 'Priya Sharma', 'Market research lead', 'Research analyst');
        teamMember($this->project, 'Rohan Das', 'Data storyteller', 'Dashboard developer', AssignmentStatus::Completed);
        teamMember($this->project, 'Ishaan Invited', 'Waiting headline', 'Pending role', AssignmentStatus::Invited);
        teamMember($this->project, 'Dev Declined', 'Declined headline', 'Declined role', AssignmentStatus::Declined);
        teamMember($this->project, 'Rhea Removed', 'Removed headline', 'Removed role', AssignmentStatus::Removed);

        Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $this->project])
            ->assertSee('Your team')
            ->assertSee('Priya')->assertSee('Research analyst')->assertSee('Market research lead')
            ->assertSee('Rohan')->assertSee('Dashboard developer')->assertSee('Data storyteller')
            ->assertDontSee('Ishaan')->assertDontSee('Pending role')
            ->assertDontSee('Dev')->assertDontSee('Declined role')
            ->assertDontSee('Rhea')->assertDontSee('Removed role');
    });

    it('never shows last names, contact details or rates', function () {
        teamMember($this->project, 'Priya Sharma', 'Research lead', 'Analyst', assignment: ['agreed_amount' => 876543]);

        Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $this->project])
            ->assertSee('Priya')
            ->assertDontSee('Sharma')
            ->assertDontSee('private.example')
            ->assertDontSee('876543');
    });

    it('stays out of sight until the SOW is accepted', function (SowStatus $status) {
        $this->sow->forceFill(['status' => $status])->save();
        teamMember($this->project, 'Priya Sharma', 'Research lead', 'Analyst');

        Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $this->project])
            ->assertDontSee('Priya')
            ->assertDontSee('Research lead')
            ->assertSee('introduced once the Statement of Work is accepted');
    })->with([SowStatus::Sent, SowStatus::Superseded]);

    it('is hidden entirely when the admin hides the team', function () {
        $this->project->forceFill(['hide_team' => true])->save();
        teamMember($this->project, 'Priya Sharma', 'Research lead', 'Analyst');

        Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $this->project->refresh()])
            ->assertDontSee('Your team')
            ->assertDontSee('Priya')
            ->assertDontSee('Research lead')
            ->assertDontSee('being put together');
    });

    it('says the team is being put together when nobody is seated yet', function () {
        Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $this->project])->assertSee('being put together');
    });

    it('copes with a contributor who has no headline', function () {
        teamMember($this->project, 'Priya Sharma', '', 'Analyst');

        Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $this->project])->assertSee('Priya')->assertSee('Analyst');
    });

    it('reads first names safely', function (string $name, string $first) {
        expect(ClientTeam::firstName($name))->toBe($first);
    })->with([
        ['Priya Sharma', 'Priya'],
        ['  Mary Ann Smith ', 'Mary'],
        ['Cher', 'Cher'],
    ]);

    it('is visible to viewers as well as owners', function () {
        teamMember($this->project, 'Priya Sharma', 'Research lead', 'Analyst');

        Livewire::actingAs($this->viewer)->test(ProjectShow::class, ['project' => $this->project])->assertSee('Priya');
    });

    it('can be hidden from the admin project form', function () {
        $dm = adminUser(AdminRole::DeliveryManager);

        Livewire::actingAs($dm)->test(EditProject::class, ['record' => $this->project->getRouteKey()])
            ->fillForm(['hide_team' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->project->refresh()->hide_team)->toBeTrue();
    });
});

describe('progress', function () {
    it('counts every milestone but lists only shared and later ones', function () {
        Milestone::factory()->status(MilestoneStatus::ClientAccepted)->create(['project_id' => $this->project->id, 'title' => 'Accepted one', 'sort' => 1]);
        Milestone::factory()->status(MilestoneStatus::Shared)->create(['project_id' => $this->project->id, 'title' => 'Shared one', 'sort' => 2]);
        Milestone::factory()->status(MilestoneStatus::ClientChangesRequested)->create(['project_id' => $this->project->id, 'title' => 'Changes one', 'sort' => 3]);
        Milestone::factory()->status(MilestoneStatus::InQa)->create(['project_id' => $this->project->id, 'title' => 'Secret in QA', 'sort' => 4]);
        Milestone::factory()->status(MilestoneStatus::InProgress)->create(['project_id' => $this->project->id, 'title' => 'Secret in progress', 'sort' => 5]);
        Milestone::factory()->create(['project_id' => $this->project->id, 'title' => 'Secret planned', 'sort' => 6]);

        Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $this->project])
            ->assertSee('1 of 6 milestones accepted')
            ->assertSeeInOrder(['Accepted one', 'Shared one', 'Changes one'])
            ->assertDontSee('Secret in QA')
            ->assertDontSee('Secret in progress')
            ->assertDontSee('Secret planned');
    });

    it('uses the singular for one milestone and shows nothing without any', function () {
        Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $this->project])->assertDontSee('Progress');

        Milestone::factory()->create(['project_id' => $this->project->id]);

        Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $this->project])
            ->assertSee('0 of 1 milestone accepted')
            ->assertSee('Finished work appears here');
    });

    it('counts milestones of this project only', function () {
        Milestone::factory()->status(MilestoneStatus::ClientAccepted)->create(['project_id' => Project::factory()->create(['organization_id' => $this->org->id])->id]);
        Milestone::factory()->create(['project_id' => $this->project->id]);

        Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $this->project])->assertSee('0 of 1 milestone accepted');
    });
});

describe('nothing internal reaches the client', function () {
    it('renders no task, comment, submission or review detail', function () {
        $seat = teamMember($this->project, 'Priya Sharma', 'Research lead', 'Analyst');
        $milestone = Milestone::factory()->status(MilestoneStatus::Shared)->create(['project_id' => $this->project->id]);
        $task = Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $milestone->id, 'assignment_id' => $seat->id, 'title' => 'Task title nobody outside should see']);
        TaskComment::factory()->internal()->create(['task_id' => $task->id, 'body' => 'Internal margin worry']);
        TaskComment::factory()->create(['task_id' => $task->id, 'body' => 'Contributor visible comment']);
        Submission::factory()->status(SubmissionStatus::QaApproved)->create(['task_id' => $task->id, 'assignment_id' => $seat->id, 'note' => 'Private contributor note', 'qa_note' => 'Private reviewer note']);
        $unapproved = Task::factory()->status(TaskStatus::Submitted)->create(['milestone_id' => Milestone::factory()->status(MilestoneStatus::InQa)->create(['project_id' => $this->project->id])->id, 'assignment_id' => $seat->id]);
        Submission::factory()->create(['task_id' => $unapproved->id, 'assignment_id' => $seat->id, 'note' => 'Unapproved work note']);

        Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $this->project])
            ->assertDontSee('Task title nobody outside')
            ->assertDontSee('Internal margin worry')
            ->assertDontSee('Contributor visible comment')
            ->assertDontSee('Private contributor note')
            ->assertDontSee('Private reviewer note')
            ->assertDontSee('Unapproved work note')
            ->assertDontSee($unapproved->title)
            ->assertDontSee('agreed')
            ->assertDontSee((string) $seat->agreed_amount);
    });

    it('keeps the page closed to other organizations and to drafts', function () {
        asUser(clientUser());
        $this->get(route('client.projects.show', $this->project))->assertForbidden();

        $this->project->forceFill(['status' => ProjectStatus::Draft])->save();

        asUser($this->owner);
        $this->get(route('client.projects.show', $this->project))->assertForbidden();
    });

    it('opens for the organization and shows the new sections on the real route', function () {
        teamMember($this->project, 'Priya Sharma', 'Research lead', 'Analyst');
        Milestone::factory()->status(MilestoneStatus::Shared)->create(['project_id' => $this->project->id, 'title' => 'Discovery']);

        asUser($this->viewer);
        $this->get(route('client.projects.show', $this->project))
            ->assertOk()
            ->assertSee('Your team')
            ->assertSee('Priya')
            ->assertSee('Progress')
            ->assertSee('Discovery');
    });
});
