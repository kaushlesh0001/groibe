<?php

namespace App\Livewire\Contributor\Work;

use App\Enums\AssignmentStatus;
use App\Enums\TaskStatus;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/** Task board for one project (spec 11): my tasks in four columns. Changes requested sit under In progress. */
#[Title('Task board')]
class ProjectBoard extends Component
{
    use WithPagination;

    public Project $project;

    public function mount(Project $project): void
    {
        $this->project = $project;

        // A contributor who is not on this project learns nothing about it, not even that it exists.
        abort_unless($this->seats()->exists(), 404);
    }

    public function render()
    {
        $tasks = Task::query()
            ->where('project_id', $this->project->getKey())
            ->whereIn('assignment_id', $this->seats()->pluck('id'))
            ->with('milestone:id,title,sort')
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate(60);

        $columns = [
            'To do' => [TaskStatus::Todo],
            'In progress' => [TaskStatus::InProgress, TaskStatus::ChangesRequested],
            'Submitted' => [TaskStatus::Submitted],
            'Approved' => [TaskStatus::Approved],
        ];

        return view('livewire.contributor.work.project-board', [
            'tasks' => $tasks,
            'columns' => collect($columns)->map(fn (array $statuses) => $tasks->getCollection()->filter(fn (Task $task) => in_array($task->status, $statuses, true))),
        ]);
    }

    /** @return Builder<Assignment> */
    private function seats()
    {
        return Assignment::query()
            ->where('project_id', $this->project->getKey())
            ->forUser(Auth::user())
            ->whereIn('status', [AssignmentStatus::Active->value, AssignmentStatus::Completed->value]);
    }
}
