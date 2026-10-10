<?php

namespace App\Livewire\Contributor\Work;

use App\Actions\Tasks\AddTaskComment;
use App\Actions\Tasks\ResumeTask;
use App\Actions\Tasks\StartTask;
use App\Actions\Tasks\SubmitWork;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Task;
use App\Services\Files\SignedFileUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Task detail (spec 11): status, comments, submission upload with version history, and resubmission. */
#[Title('Task')]
class TaskDetail extends Component
{
    use WithFileUploads;

    public Task $task;

    public string $note = '';

    /** @var array<int, UploadedFile> */
    public array $uploads = [];

    public string $comment = '';

    public function mount(Task $task): void
    {
        // Not yours: the same 404 as a task that does not exist.
        abort_unless(Gate::forUser(Auth::user())->allows('view', $task), 404);

        $this->task = $task;
    }

    public function start(): void
    {
        $this->run(fn () => app(StartTask::class)($this->task, Auth::user()), 'Task started.');
    }

    public function resume(): void
    {
        $this->run(fn () => app(ResumeTask::class)($this->task, Auth::user()), 'You can now revise and submit again.');
    }

    public function submit(): void
    {
        $this->resetErrorBag();

        $ok = $this->run(function (): void {
            app(SubmitWork::class)($this->task, Auth::user(), $this->note, array_values($this->uploads));
        }, 'Work submitted. Groibe will review it.');

        if ($ok) {
            $this->reset('note', 'uploads');
        }
    }

    public function addComment(): void
    {
        $this->resetErrorBag();

        $ok = $this->run(fn () => app(AddTaskComment::class)($this->task, Auth::user(), $this->comment), 'Comment added.');

        if ($ok) {
            $this->reset('comment');
        }
    }

    public function render()
    {
        $this->task->load('milestone:id,title,project_id', 'milestone.project:id,uuid,title');

        $submissions = $this->task->submissions()->with('media')->orderByDesc('version')->get();
        $links = app(SignedFileUrl::class);

        return view('livewire.contributor.work.task-detail', [
            'project' => $this->task->milestone->project,
            'comments' => $this->task->comments()->where('is_internal', false)->with('user:id,name')->oldest()->limit(200)->get(),
            'submissions' => $submissions,
            'files' => $submissions->mapWithKeys(fn ($submission) => [$submission->id => $links->describe($submission->media)]),
            'canWork' => Gate::forUser(Auth::user())->allows('work', $this->task),
            'canComment' => Gate::forUser(Auth::user())->allows('comment', $this->task),
            'maxFiles' => (int) config('groibe.uploads.submission_max_files'),
            'maxMb' => (int) config('groibe.uploads.submission_max_kb') / 1024,
            'extensions' => implode(', ', config('groibe.uploads.document_extensions')),
            'statusIs' => fn (TaskStatus $status): bool => $this->task->status === $status,
        ]);
    }

    private function run(\Closure $callback, string $success): bool
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            $this->addError('uploads', collect($e->errors())->flatten()->first());

            return false;
        } catch (ActionBlocked|IllegalTransition $e) {
            $this->addError('action', $e->getMessage());

            return false;
        } catch (AuthorizationException) {
            $this->addError('action', 'You cannot do that on this task.');

            return false;
        }

        $this->task->refresh();
        session()->flash('status', $success);

        return true;
    }
}
