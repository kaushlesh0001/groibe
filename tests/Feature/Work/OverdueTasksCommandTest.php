<?php

use App\Console\Commands\NotifyOverdueTasks;
use App\Enums\AdminRole;
use App\Enums\OnboardingState;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Notifications\TasksOverdue;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 08:00:00');
    Notification::fake();

    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $this->dm->id, 'title' => 'Market study']);
    $this->milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
    $this->seat = Assignment::factory()->active()->create(['project_id' => $this->project->id]);
});

afterEach(fn () => Carbon::setTestNow());

function overdue(Milestone $milestone, string $title, string $due = '2026-10-15', TaskStatus $status = TaskStatus::InProgress, ?Assignment $seat = null): Task
{
    return Task::factory()->status($status)->create([
        'milestone_id' => $milestone->id,
        'assignment_id' => $seat?->id,
        'title' => $title,
        'due_date' => $due,
    ]);
}

it('sends the delivery manager one digest per project with the overdue tasks', function () {
    overdue($this->milestone, 'Survey draft', '2026-10-10', seat: $this->seat);
    overdue($this->milestone, 'Interview guide', '2026-10-18', TaskStatus::Todo);
    overdue($this->milestone, 'Waiting on QA', '2026-10-17', TaskStatus::Submitted, $this->seat);

    $this->artisan('groibe:notify-overdue-tasks')->expectsOutputToContain('Sent 1 overdue task digest.')->assertSuccessful();

    Notification::assertSentToTimes($this->dm, TasksOverdue::class, 1);
    Notification::assertSentTo($this->dm, TasksOverdue::class, function (TasksOverdue $n) {
        return $n->project->is($this->project)
            && $n->total === 3
            && array_column($n->tasks, 'title') === ['Survey draft', 'Waiting on QA', 'Interview guide']
            && $n->tasks[1]['status'] === 'Submitted'
            && $n->tasks[2]['assignee'] === 'Unassigned'
            && $n->tasks[0]['assignee'] === $this->seat->user->name;
    });
});

it('leaves out tasks that are not overdue', function () {
    overdue($this->milestone, 'Approved late', '2026-10-01', TaskStatus::Approved);
    overdue($this->milestone, 'Due today', '2026-10-20');
    overdue($this->milestone, 'Due tomorrow', '2026-10-21');
    Task::factory()->create(['milestone_id' => $this->milestone->id, 'title' => 'No date', 'due_date' => null]);

    $this->artisan('groibe:notify-overdue-tasks')->expectsOutputToContain('Sent 0 overdue task digests.');

    Notification::assertNothingSent();
});

it('counts a task as overdue from the day after its due date', function () {
    overdue($this->milestone, 'Yesterday', '2026-10-19');

    $this->artisan('groibe:notify-overdue-tasks');

    Notification::assertSentTo($this->dm, TasksOverdue::class, fn (TasksOverdue $n) => $n->total === 1);
});

it('only checks projects that are being worked', function (ProjectStatus $status, bool $notified) {
    $this->project->forceFill(['status' => $status])->save();
    overdue($this->milestone, 'Late task');

    $this->artisan('groibe:notify-overdue-tasks');

    $notified ? Notification::assertSentTo($this->dm, TasksOverdue::class) : Notification::assertNothingSent();
})->with([
    [ProjectStatus::Active, true],
    [ProjectStatus::InQa, true],
    [ProjectStatus::OnHold, false],
    [ProjectStatus::Staffing, false],
    [ProjectStatus::Delivered, false],
    [ProjectStatus::Accepted, false],
    [ProjectStatus::Closed, false],
    [ProjectStatus::Cancelled, false],
]);

it('keeps each delivery manager to their own projects', function () {
    $otherDm = adminUser(AdminRole::DeliveryManager);
    $other = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $otherDm->id, 'title' => 'Other study']);
    overdue($this->milestone, 'Mine');
    overdue(Milestone::factory()->create(['project_id' => $other->id]), 'Theirs');

    $this->artisan('groibe:notify-overdue-tasks')->expectsOutputToContain('Sent 2 overdue task digests.');

    Notification::assertSentToTimes($this->dm, TasksOverdue::class, 1);
    Notification::assertSentToTimes($otherDm, TasksOverdue::class, 1);
    Notification::assertSentTo($this->dm, TasksOverdue::class, fn (TasksOverdue $n) => $n->project->is($this->project) && array_column($n->tasks, 'title') === ['Mine']);
    Notification::assertSentTo($otherDm, TasksOverdue::class, fn (TasksOverdue $n) => $n->project->is($other) && array_column($n->tasks, 'title') === ['Theirs']);
});

it('lists the most overdue first and caps the list while stating the total', function () {
    foreach (range(1, 20) as $day) {
        overdue($this->milestone, "Task {$day}", Carbon::parse('2026-10-19')->subDays($day)->toDateString());
    }

    $this->artisan('groibe:notify-overdue-tasks');

    Notification::assertSentTo($this->dm, TasksOverdue::class, function (TasksOverdue $n) {
        return $n->total === 20
            && count($n->tasks) === NotifyOverdueTasks::LIST_LIMIT
            && $n->tasks[0]['title'] === 'Task 20'
            && str_contains($n->headline(), '20 tasks are overdue');
    });
});

it('falls back to admins who can edit tasks when the project has no delivery manager', function () {
    $this->project->forceFill(['delivery_manager_id' => null])->save();
    $superAdmin = adminUser(AdminRole::SuperAdmin);
    $finance = adminUser(AdminRole::FinanceOps);
    $community = adminUser(AdminRole::CommunityManager);
    overdue($this->milestone, 'Late task');

    $this->artisan('groibe:notify-overdue-tasks');

    Notification::assertSentTo($this->dm, TasksOverdue::class);
    Notification::assertSentTo($superAdmin, TasksOverdue::class);
    Notification::assertNotSentTo($finance, TasksOverdue::class);
    Notification::assertNotSentTo($community, TasksOverdue::class);
});

it('never tells contributors or clients', function () {
    $contributor = contributorIn(OnboardingState::Active);
    $client = clientUser($this->project->organization);
    overdue($this->milestone, 'Late task', seat: $this->seat);

    $this->artisan('groibe:notify-overdue-tasks');

    Notification::assertNotSentTo([$this->seat->user, $contributor, $client], TasksOverdue::class);
});

it('writes a readable digest', function () {
    overdue($this->milestone, 'Survey draft', '2026-10-10', seat: $this->seat);

    $notification = new TasksOverdue($this->project, 1, [['title' => 'Survey draft', 'due' => 'Oct 10, 2026', 'status' => 'In progress', 'assignee' => 'Priya']]);
    $html = (string) $notification->toMail($this->dm)->render();

    expect($notification->headline())->toBe('1 task is overdue on Market study')
        ->and($html)->toContain('Survey draft')->toContain('Oct 10, 2026')->toContain('Priya')
        ->and($notification->toArray($this->dm)['overdue_count'])->toBe(1);
});

it('is scheduled once a day', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'groibe:notify-overdue-tasks'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 8 * * *');
});
