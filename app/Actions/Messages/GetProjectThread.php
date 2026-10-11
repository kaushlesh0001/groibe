<?php

namespace App\Actions\Messages;

use App\Enums\ProjectStatus;
use App\Enums\ThreadType;
use App\Exceptions\ActionBlocked;
use App\Models\Project;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;

/**
 * The one conversation of a project (spec 14), created when the SOW is sent and on first use for older projects.
 * Nothing exists while the project is a draft, because clients do not see drafts (spec 22).
 */
class GetProjectThread
{
    /** For a person opening the conversation: they must belong to it. */
    public function __invoke(Project $project, User $actor): Thread
    {
        Gate::forUser($actor)->authorize('message', $project);

        return $this->ensure($project, $actor);
    }

    /** For the system (for example when a SOW is sent). No person check: callers have already authorized their own action. */
    public function ensure(Project $project, User $creator): Thread
    {
        if ($project->status === ProjectStatus::Draft) {
            throw ActionBlocked::guardFailed('The project conversation opens once the Statement of Work is sent.');
        }

        $attributes = ['project_id' => $project->getKey(), 'type' => ThreadType::Project->value];
        $thread = Thread::query()->where($attributes)->first();

        if ($thread !== null) {
            return $thread;
        }

        try {
            return Thread::query()->create([
                ...$attributes,
                'subject' => "{$project->code}: {$project->title}",
                'created_by' => $project->delivery_manager_id ?? $creator->getKey(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two people opened it at once: the other request won, so use theirs.
            return Thread::query()->where($attributes)->firstOrFail();
        }
    }
}
