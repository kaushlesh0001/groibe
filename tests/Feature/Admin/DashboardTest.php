<?php

use App\Enums\AdminRole;
use App\Enums\ApplicationStatus;
use App\Enums\BriefStatus;
use App\Enums\OnboardingState;
use App\Enums\ProjectStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Filament\Admin\Widgets\ApplicationsAwaitingWidget;
use App\Filament\Admin\Widgets\BriefsByStatusWidget;
use App\Filament\Admin\Widgets\ContributorsByStateWidget;
use App\Filament\Admin\Widgets\OrganizationsByStatusWidget;
use App\Filament\Admin\Widgets\OverdueTasksWidget;
use App\Filament\Admin\Widgets\PipelineFunnelWidget;
use App\Filament\Admin\Widgets\ProjectsByStatusWidget;
use App\Filament\Admin\Widgets\QaQueueWidget;
use App\Filament\Admin\Widgets\ReviewsAwaitingModerationWidget;
use App\Filament\Admin\Widgets\SystemStatusWidget;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\Brief;
use App\Models\Milestone;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Review;
use App\Models\Submission;
use App\Models\Task;
use App\Models\User;
use App\Services\Admin\DashboardMetrics;
use App\Services\Ops\CronHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** A running project with one task on it, and a seat for a contributor. @return array{0: Project, 1: Task, 2: Assignment} */
function runningProjectWithTask(array $task = [], ProjectStatus $status = ProjectStatus::Active): array
{
    $project = Project::factory()->status($status)->create();
    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    $assignment = Assignment::factory()->create(['project_id' => $project->id]);
    $task = Task::factory()->create(['milestone_id' => $milestone->id, 'assignment_id' => $assignment->id, ...$task]);

    return [$project, $task, $assignment];
}

describe('visibility by role', function () {
    it('shows every widget to a super admin', function () {
        $this->actingAs(adminUser())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Contributors by state')
            ->assertSee('Organizations')
            ->assertSee('Briefs by status')
            ->assertSee('Projects by status')
            ->assertSee('Pipeline: brief to closed project')
            ->assertSee('QA queue')
            ->assertSee('Overdue tasks')
            ->assertSee('Applications awaiting action')
            ->assertSee('Reviews awaiting moderation')
            ->assertSee('System status');
    });

    it('shows a delivery manager the delivery widgets but not system status', function () {
        $this->actingAs(adminUser(AdminRole::DeliveryManager))
            ->get('/admin')
            ->assertOk()
            ->assertSee('QA queue')
            ->assertSee('Overdue tasks')
            ->assertSee('Briefs by status')
            ->assertDontSee('System status');
    });

    it('shows a community manager the community widgets and read-only delivery counts, but no work queues', function () {
        // Spec 5: community managers read briefs and projects, but hold nothing on tasks or submissions.
        $this->actingAs(adminUser(AdminRole::CommunityManager))
            ->get('/admin')
            ->assertOk()
            ->assertSee('Contributors by state')
            ->assertSee('Applications awaiting action')
            ->assertSee('Reviews awaiting moderation')
            ->assertSee('Briefs by status')
            ->assertDontSee('QA queue')
            ->assertDontSee('Overdue tasks')
            ->assertDontSee('System status');
    });

    it('shows finance only what it may read', function () {
        $this->actingAs(adminUser(AdminRole::FinanceOps))
            ->get('/admin')
            ->assertOk()
            ->assertSee('Projects by status')
            ->assertDontSee('QA queue')
            ->assertDontSee('Overdue tasks')
            ->assertDontSee('Applications awaiting action')
            ->assertDontSee('Reviews awaiting moderation')
            ->assertDontSee('System status');
    });

    it('decides widget access from permissions, so the widget classes refuse other roles', function (string $widget, AdminRole $role, bool $allowed) {
        $this->actingAs(adminUser($role));

        expect($widget::canView())->toBe($allowed);
    })->with([
        'system status, super admin' => [SystemStatusWidget::class, AdminRole::SuperAdmin, true],
        'system status, delivery manager' => [SystemStatusWidget::class, AdminRole::DeliveryManager, false],
        'system status, finance' => [SystemStatusWidget::class, AdminRole::FinanceOps, false],
        'system status, community manager' => [SystemStatusWidget::class, AdminRole::CommunityManager, false],
        'QA queue, community manager' => [QaQueueWidget::class, AdminRole::CommunityManager, false],
        'QA queue, delivery manager' => [QaQueueWidget::class, AdminRole::DeliveryManager, true],
        'overdue tasks, finance' => [OverdueTasksWidget::class, AdminRole::FinanceOps, false],
        'funnel, finance (no briefs)' => [PipelineFunnelWidget::class, AdminRole::FinanceOps, false],
        'funnel, community manager' => [PipelineFunnelWidget::class, AdminRole::CommunityManager, true],
    ]);

    it('hides every widget from guests and from non-admin users', function (string $widget) {
        expect($widget::canView())->toBeFalse();

        $this->actingAs(contributorUser());
        expect($widget::canView())->toBeFalse();
    })->with([
        ContributorsByStateWidget::class,
        OrganizationsByStatusWidget::class,
        BriefsByStatusWidget::class,
        ProjectsByStatusWidget::class,
        PipelineFunnelWidget::class,
        QaQueueWidget::class,
        OverdueTasksWidget::class,
        ApplicationsAwaitingWidget::class,
        ReviewsAwaitingModerationWidget::class,
        SystemStatusWidget::class,
    ]);

    it('keeps the dashboard closed to guests and to contributors', function () {
        $this->get('/admin')->assertRedirect();

        $this->actingAs(contributorUser())->get('/admin')->assertForbidden();
    });
});

describe('counts', function () {
    it('counts contributors by onboarding state and leaves clients out', function () {
        foreach ([OnboardingState::Active, OnboardingState::Active, OnboardingState::PendingReview] as $state) {
            Profile::factory()->inState($state)->create(['user_id' => User::factory()->contributor()]);
        }
        Profile::factory()->inState(OnboardingState::Active)->create(['user_id' => User::factory()->client()]);

        $counts = app(DashboardMetrics::class)->contributorsByState();

        expect($counts['Active'])->toBe(2)
            ->and($counts['Pending Review'])->toBe(1)
            ->and($counts['Suspended'])->toBe(0)
            ->and($counts)->not->toHaveKey('Org Verified');
    });

    it('counts briefs and projects by status, with a row for every status', function () {
        Brief::factory()->status(BriefStatus::InReview)->count(2)->create();
        Brief::factory()->status(BriefStatus::Draft)->create();
        Project::factory()->status(ProjectStatus::Active)->create();

        $metrics = app(DashboardMetrics::class);

        expect($metrics->briefsByStatus())->toMatchArray(['Draft' => 1, 'In Review' => 2, 'Declined' => 0])
            ->and($metrics->projectsByStatus())->toMatchArray(['Active' => 1, 'Closed' => 0])
            ->and(array_keys($metrics->projectsByStatus()))->toHaveCount(count(ProjectStatus::cases()));
    });

    it('builds the funnel from the furthest stage each project reached', function () {
        Brief::factory()->status(BriefStatus::Draft)->create();
        Brief::factory()->status(BriefStatus::Submitted)->create();
        Brief::factory()->status(BriefStatus::Scoped)->create();
        Brief::factory()->status(BriefStatus::Converted)->create();

        Project::factory()->status(ProjectStatus::Draft)->create();
        Project::factory()->status(ProjectStatus::Staffing)->create();
        Project::factory()->status(ProjectStatus::Closed)->create();
        Project::factory()->status(ProjectStatus::Cancelled)->create();
        // On hold counts at the stage it was held from.
        Project::factory()->status(ProjectStatus::OnHold)->create(['status_before_hold' => ProjectStatus::Active->value]);

        expect(app(DashboardMetrics::class)->pipelineFunnel())->toBe([
            'Briefs submitted' => 3,
            'Briefs scoped' => 2,
            'Projects created' => 4,
            'SOW accepted' => 3,
            'Work active' => 2,
            'Delivered' => 1,
            'Closed' => 1,
        ]);
    });

    it('lists the oldest submissions awaiting QA and ignores reviewed ones', function () {
        [, $task, $assignment] = runningProjectWithTask(['title' => 'Draft the market scan']);
        Submission::factory()->create(['task_id' => $task->id, 'assignment_id' => $assignment->id]);
        Submission::factory()->status(SubmissionStatus::QaApproved)->create(['task_id' => $task->id, 'assignment_id' => $assignment->id, 'version' => 2]);

        $queue = app(DashboardMetrics::class)->qaQueue();

        expect($queue['total'])->toBe(1)
            ->and($queue['items'])->toHaveCount(1)
            ->and($queue['items'][0]['title'])->toBe('Draft the market scan');
    });

    it('counts overdue tasks only on running projects and skips approved and undated tasks', function () {
        runningProjectWithTask(['due_date' => now()->subDays(3)]);
        runningProjectWithTask(['due_date' => now()->subDay()], ProjectStatus::InQa);
        runningProjectWithTask(['due_date' => now()->subDays(3), 'status' => TaskStatus::Approved]);
        runningProjectWithTask(['due_date' => now()->addDay()]);
        runningProjectWithTask(['due_date' => null]);
        runningProjectWithTask(['due_date' => now()->subDays(3)], ProjectStatus::OnHold);

        $overdue = app(DashboardMetrics::class)->overdueTasks();

        expect($overdue['total'])->toBe(2)
            ->and($overdue['items'])->toHaveCount(2);
    });

    it('caps the lists but states the full total', function () {
        for ($i = 0; $i < DashboardMetrics::LIST_LIMIT + 2; $i++) {
            runningProjectWithTask(['due_date' => now()->subDays(2)]);
        }

        $overdue = app(DashboardMetrics::class)->overdueTasks();

        expect($overdue['total'])->toBe(DashboardMetrics::LIST_LIMIT + 2)
            ->and($overdue['items'])->toHaveCount(DashboardMetrics::LIST_LIMIT);
    });

    it('counts the applications an admin has to act on', function () {
        Application::factory()->count(2)->create();
        Application::factory()->status(ApplicationStatus::Shortlisted)->create();
        Application::factory()->status(ApplicationStatus::Offered)->create();
        Application::factory()->status(ApplicationStatus::Withdrawn)->create();

        expect(app(DashboardMetrics::class)->applicationsAwaitingAction())
            ->toBe(['To review' => 2, 'Shortlisted, to decide' => 1]);
    });

    it('counts only reviews waiting for moderation', function () {
        Review::factory()->count(2)->create();
        Review::factory()->published()->create();

        expect(app(DashboardMetrics::class)->reviewsAwaitingModeration())->toBe(2);
    });

    it('renders the counts on the widgets', function () {
        Brief::factory()->status(BriefStatus::NeedsInfo)->count(3)->create();
        $this->actingAs(adminUser());

        Livewire::test(BriefsByStatusWidget::class)->assertSee('Needs Info')->assertSee('3');
    });
});

describe('system status', function () {
    it('reports no cron run until the scheduler has beaten once', function () {
        Cache::forget(CronHeartbeat::KEY);
        $this->actingAs(adminUser());

        Livewire::test(SystemStatusWidget::class)->assertSee('No run recorded yet');
    });

    it('reports a recent beat as running and an old one as stopped', function () {
        $heartbeat = app(CronHeartbeat::class);
        $this->actingAs(adminUser());

        $heartbeat->record();
        expect($heartbeat->isHealthy())->toBeTrue();
        Livewire::test(SystemStatusWidget::class)->assertSee('Running');

        $this->travel(CronHeartbeat::STALE_AFTER_MINUTES + 1)->minutes();
        expect($heartbeat->isHealthy())->toBeFalse();
        Livewire::test(SystemStatusWidget::class)->assertSee('Stopped');
    });

    it('shows the queue backlog and the failed jobs', function () {
        $now = now()->getTimestamp();
        DB::table('jobs')->insert([
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => $now, 'created_at' => $now - 600],
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => $now, 'created_at' => $now],
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
        ]);

        expect(app(DashboardMetrics::class)->queueHealth())->toBe(['waiting' => 2, 'failed' => 1, 'oldest_minutes' => 10]);
    });

    it('reports an empty queue without an age', function () {
        expect(app(DashboardMetrics::class)->queueHealth())->toBe(['waiting' => 0, 'failed' => 0, 'oldest_minutes' => null]);
    });

    it('records the heartbeat from the scheduler every minute', function () {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'cron-heartbeat');

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('* * * * *');

        Cache::forget(CronHeartbeat::KEY);
        $event->run(app());

        expect(app(CronHeartbeat::class)->isHealthy())->toBeTrue();
    });

    it('keeps the queue worker on the schedule', function () {
        $worker = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'queue:work'));

        expect($worker)->not->toBeNull()
            ->and($worker->expression)->toBe('* * * * *')
            ->and($worker->command)->toContain('--stop-when-empty');
    });
});

describe('query counts', function () {
    it('makes the same number of queries when the data grows', function () {
        $queries = function (): int {
            $metrics = app(DashboardMetrics::class);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $metrics->contributorsByState();
            $metrics->organizationsByStatus();
            $metrics->briefsByStatus();
            $metrics->projectsByStatus();
            $metrics->pipelineFunnel();
            $metrics->qaQueue();
            $metrics->overdueTasks();
            $metrics->applicationsAwaitingAction();
            $metrics->reviewsAwaitingModeration();
            $metrics->queueHealth();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        [, $task, $assignment] = runningProjectWithTask(['due_date' => now()->subDay()]);
        Submission::factory()->create(['task_id' => $task->id, 'assignment_id' => $assignment->id]);
        $before = $queries();

        for ($i = 0; $i < 4; $i++) {
            [, $task, $assignment] = runningProjectWithTask(['due_date' => now()->subDay()]);
            Submission::factory()->create(['task_id' => $task->id, 'assignment_id' => $assignment->id]);
        }
        $after = $queries();

        expect($after)->toBe($before);
    });
});
