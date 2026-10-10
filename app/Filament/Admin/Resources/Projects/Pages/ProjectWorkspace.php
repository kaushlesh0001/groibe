<?php

namespace App\Filament\Admin\Resources\Projects\Pages;

use App\Actions\Milestones\ReorderMilestones;
use App\Actions\Tasks\AssignTask;
use App\Filament\Admin\Resources\Projects\ProjectResource;
use App\Filament\Admin\Resources\Projects\Support\WorkPlanActions;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

/** Planning view for delivery managers: milestones in order with their tasks, progress, and quick assignment. */
class ProjectWorkspace extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.admin.projects.workspace';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(
            auth()->user()?->can('view', $this->project()) && auth()->user()->can('viewAny', Milestone::class),
            403,
        );
    }

    public function getTitle(): string
    {
        return "Workspace: {$this->project()->code}";
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            WorkPlanActions::addMilestone(fn (): Project => $this->project()),
            WorkPlanActions::addTask(fn (): Project => $this->project()),
            Action::make('backToProject')
                ->label('Project details')
                ->color('gray')
                ->url(fn (): string => ProjectResource::getUrl('edit', ['record' => $this->project()])),
        ];
    }

    public function moveMilestone(int $milestoneId, string $direction): void
    {
        $ids = $this->project()->milestones()->ordered()->pluck('id')->all();
        $position = array_search($milestoneId, $ids, true);
        $target = $direction === 'up' ? $position - 1 : $position + 1;

        if ($position === false || ! isset($ids[$target])) {
            return;
        }

        [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];

        DomainActionRunner::run(fn () => app(ReorderMilestones::class)($this->project(), auth()->user(), $ids), 'Order saved');
    }

    public function assignTask(int $taskId, ?string $assignmentId): void
    {
        $task = Task::query()->where('project_id', $this->project()->getKey())->findOrFail($taskId);

        DomainActionRunner::run(
            fn () => app(AssignTask::class)($task, auth()->user(), $assignmentId === '' ? null : $assignmentId),
            'Assignment saved',
        );
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $project = $this->project();

        /** @var Collection<int, Milestone> $milestones */
        $milestones = $project->milestones()
            ->ordered()
            ->with(['tasks' => fn ($tasks) => $tasks->orderBy('id'), 'tasks.assignment:id,user_id,role_title', 'tasks.assignment.user:id,name'])
            ->get();

        return [
            'milestones' => $milestones,
            'assignmentOptions' => WorkPlanActions::assignmentOptions($project),
            'canAssign' => (bool) auth()->user()?->can('assign', new Task),
            'canReorder' => (bool) auth()->user()?->can('reorder', Milestone::class),
        ];
    }

    private function project(): Project
    {
        /** @var Project */
        return $this->getRecord();
    }
}
