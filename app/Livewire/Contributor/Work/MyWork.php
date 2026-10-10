<?php

namespace App\Livewire\Contributor\Work;

use App\Actions\Assignments\AcceptAssignment;
use App\Actions\Assignments\DeclineAssignment;
use App\Enums\AssignmentStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Assignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/** My Work (spec 11): the projects I am invited to or working on, with accept and decline for open invites. */
#[Title('My work')]
class MyWork extends Component
{
    use WithPagination;

    public function accept(int $assignmentId): void
    {
        $this->respond($assignmentId, AcceptAssignment::class, 'Invite accepted. Welcome to the project.');
    }

    public function decline(int $assignmentId): void
    {
        $this->respond($assignmentId, DeclineAssignment::class, 'Invite declined.');
    }

    public function render()
    {
        $assignments = Assignment::query()
            ->forUser(Auth::user())
            ->whereIn('status', [AssignmentStatus::Invited->value, AssignmentStatus::Active->value, AssignmentStatus::Completed->value])
            ->with('project:id,uuid,title,code,status,due_date')
            ->withCount([
                'tasks',
                'tasks as approved_tasks_count' => fn ($tasks) => $tasks->where('status', TaskStatus::Approved->value),
            ])
            ->latest('invited_at')
            ->paginate(15);

        return view('livewire.contributor.work.my-work', ['assignments' => $assignments]);
    }

    /** @param  class-string<AcceptAssignment|DeclineAssignment>  $action */
    private function respond(int $assignmentId, string $action, string $success): void
    {
        $assignment = Assignment::query()->forUser(Auth::user())->findOrFail($assignmentId);

        try {
            app($action)($assignment, Auth::user());
            session()->flash('status', $success);
        } catch (ActionBlocked|IllegalTransition|AuthorizationException $e) {
            session()->flash('error', $e instanceof AuthorizationException ? 'That invite can no longer be answered.' : $e->getMessage());
        }
    }
}
