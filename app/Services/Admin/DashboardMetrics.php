<?php

namespace App\Services\Admin;

use App\Enums\ApplicationStatus;
use App\Enums\BriefStatus;
use App\Enums\OnboardingState;
use App\Enums\OrganizationStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReviewStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\Application;
use App\Models\Brief;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Review;
use App\Models\Submission;
use App\Models\Task;
use BackedEnum;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only counts behind the admin dashboard widgets (spec 13). Every method runs a fixed number of queries
 * whatever the data size, and lists are capped, so the dashboard never grows with the data.
 */
class DashboardMetrics
{
    /** Rows shown in a "needs attention" list; the total is always stated. */
    public const LIST_LIMIT = 5;

    /** Contributor states an admin works with (client-only states are left out). */
    private const CONTRIBUTOR_STATES = [
        OnboardingState::Registered,
        OnboardingState::ProfileComplete,
        OnboardingState::PendingReview,
        OnboardingState::Active,
        OnboardingState::Rejected,
        OnboardingState::Suspended,
    ];

    /** Project stages in the order work moves through them (spec 6.2). */
    private const PROJECT_FLOW = [
        ProjectStatus::Draft,
        ProjectStatus::SowSent,
        ProjectStatus::SowAccepted,
        ProjectStatus::Staffing,
        ProjectStatus::Active,
        ProjectStatus::InQa,
        ProjectStatus::Delivered,
        ProjectStatus::Accepted,
        ProjectStatus::Closed,
    ];

    /** @return array<string, int> */
    public function contributorsByState(): array
    {
        $counts = Profile::query()
            ->whereHas('user', fn (Builder $users) => $users->where('role', UserRole::Contributor->value))
            ->toBase()
            ->selectRaw('onboarding_state as bucket, count(*) as total')
            ->groupBy('onboarding_state')
            ->pluck('total', 'bucket');

        return $this->labelled(self::CONTRIBUTOR_STATES, $counts->all());
    }

    /** @return array<string, int> */
    public function organizationsByStatus(): array
    {
        return $this->labelled(OrganizationStatus::cases(), $this->grouped(Organization::query(), 'status'));
    }

    /** @return array<string, int> */
    public function briefsByStatus(): array
    {
        return $this->labelled(BriefStatus::cases(), $this->grouped(Brief::query(), 'status'));
    }

    /** @return array<string, int> */
    public function projectsByStatus(): array
    {
        return $this->labelled(ProjectStatus::cases(), $this->grouped(Project::query(), 'status'));
    }

    /**
     * Briefs to delivered work. A project counts at the furthest stage it has reached, so one on hold counts at the
     * stage it was held from, and cancelled projects are left out. Projects created without a brief are counted
     * from "Projects created", so a stage can exceed the one before it.
     *
     * @return array<string, int>
     */
    public function pipelineFunnel(): array
    {
        $briefs = $this->grouped(Brief::query(), 'status');

        $stages = Project::query()
            ->toBase()
            ->selectRaw("case when status = 'on_hold' then status_before_hold else status end as bucket, count(*) as total")
            ->where('status', '!=', ProjectStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->all();

        return [
            'Briefs submitted' => array_sum($briefs) - ($briefs[BriefStatus::Draft->value] ?? 0),
            'Briefs scoped' => ($briefs[BriefStatus::Scoped->value] ?? 0) + ($briefs[BriefStatus::Converted->value] ?? 0),
            'Projects created' => $this->reached($stages, ProjectStatus::Draft),
            'SOW accepted' => $this->reached($stages, ProjectStatus::SowAccepted),
            'Work active' => $this->reached($stages, ProjectStatus::Active),
            'Delivered' => $this->reached($stages, ProjectStatus::Delivered),
            'Closed' => $this->reached($stages, ProjectStatus::Closed),
        ];
    }

    /** @return array{total: int, items: list<array{title: string, project: string, who: string, since: string}>} */
    public function qaQueue(): array
    {
        $query = Submission::query()->where('status', SubmissionStatus::Submitted->value);

        $items = (clone $query)
            ->with(['task:id,title,project_id', 'task.project:id,code', 'user:id,name'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (Submission $submission): array => [
                'title' => $submission->task->title,
                'project' => $submission->task->project->code,
                'who' => $submission->user->name,
                'since' => $submission->created_at->diffForHumans(),
            ])
            ->all();

        return ['total' => $query->count(), 'items' => array_values($items)];
    }

    /** @return array{total: int, items: list<array{title: string, project: string, due: string}>} */
    public function overdueTasks(): array
    {
        $running = fn (Builder $projects) => $projects
            ->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::InQa->value]);

        $query = Task::query()->overdue()->whereHas('project', $running);

        $items = (clone $query)
            ->with('project:id,code')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (Task $task): array => [
                'title' => $task->title,
                'project' => $task->project->code,
                'due' => $task->due_date->toFormattedDateString(),
            ])
            ->all();

        return ['total' => $query->count(), 'items' => array_values($items)];
    }

    /**
     * Applications that wait for an admin: new ones to shortlist or reject, and shortlisted ones to decide.
     * Offered ones wait for the contributor, so they are not counted.
     *
     * @return array<string, int>
     */
    public function applicationsAwaitingAction(): array
    {
        $counts = $this->grouped(Application::query(), 'status');

        return [
            'To review' => $counts[ApplicationStatus::Submitted->value] ?? 0,
            'Shortlisted, to decide' => $counts[ApplicationStatus::Shortlisted->value] ?? 0,
        ];
    }

    public function reviewsAwaitingModeration(): int
    {
        return Review::query()->where('status', ReviewStatus::PendingModeration->value)->count();
    }

    /**
     * Waiting jobs, failed jobs, and the age of the oldest waiting job in minutes.
     *
     * @return array{waiting: int, failed: int, oldest_minutes: int|null}
     */
    public function queueHealth(): array
    {
        $jobs = DB::table((string) config('queue.connections.database.table', 'jobs'));
        $oldest = (clone $jobs)->min('created_at');

        return [
            'waiting' => $jobs->count(),
            'failed' => DB::table((string) config('queue.failed.table', 'failed_jobs'))->count(),
            'oldest_minutes' => $oldest === null ? null : max(0, (int) floor((now()->getTimestamp() - (int) $oldest) / 60)),
        ];
    }

    /** @return array<string, int> */
    private function grouped(Builder $query, string $column): array
    {
        return $query->toBase()
            ->selectRaw("$column as bucket, count(*) as total")
            ->groupBy($column)
            ->pluck('total', 'bucket')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * Every case gets a row, so a state with no records shows 0 instead of vanishing.
     *
     * @param  list<BackedEnum&HasLabel>  $cases
     * @param  array<string, int|string>  $counts
     * @return array<string, int>
     */
    private function labelled(array $cases, array $counts): array
    {
        $rows = [];

        foreach ($cases as $case) {
            $rows[(string) $case->getLabel()] = (int) ($counts[$case->value] ?? 0);
        }

        return $rows;
    }

    /** @param array<string, int|string> $stages */
    private function reached(array $stages, ProjectStatus $from): int
    {
        $total = 0;
        $start = (int) array_search($from, self::PROJECT_FLOW, true);

        foreach (array_slice(self::PROJECT_FLOW, $start) as $stage) {
            $total += (int) ($stages[$stage->value] ?? 0);
        }

        return $total;
    }
}
