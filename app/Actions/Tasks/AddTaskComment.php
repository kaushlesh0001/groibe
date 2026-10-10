<?php

namespace App\Actions\Tasks;

use App\Exceptions\ActionBlocked;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** A contributor comment on their own task. Contributor comments are never internal (spec 7.3). */
class AddTaskComment
{
    public function __invoke(Task $task, User $actor, string $body): TaskComment
    {
        Gate::forUser($actor)->authorize('comment', $task);

        $body = trim($body);

        if ($body === '') {
            throw ActionBlocked::guardFailed('Write a comment first.');
        }

        $comment = TaskComment::query()->create([
            'task_id' => $task->getKey(),
            'user_id' => $actor->getKey(),
            'body' => $body,
            'is_internal' => false,
        ]);

        activity('task')->causedBy($actor)->performedOn($task)->event('commented')
            ->withProperties(['comment_id' => $comment->getKey()])->log('Comment added');

        return $comment;
    }
}
