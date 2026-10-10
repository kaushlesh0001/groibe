<?php

namespace App\Policies;

use App\Models\TaskComment;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * Task comments (spec 7.3): `is_internal` hides a comment from everyone outside the delivery team. Delivery
 * managers read all comments. A contributor reads the non-internal comments on tasks they hold. Clients never
 * read task comments. Comments are not edited or deleted, so those abilities are not granted.
 */
class TaskCommentPolicy
{
    use ChecksOrganizationAccess;

    public function view(User $actor, TaskComment $comment): bool
    {
        if ($this->adminCan($actor, 'tasks.view')) {
            return true;
        }

        if (! $actor->isContributor() || $comment->is_internal) {
            return false;
        }

        $comment->loadMissing('task.assignment');

        return $comment->task->assignment?->user_id === $actor->getKey();
    }

    /** Who may start a comment at all. Which task is decided by TaskPolicy::comment. */
    public function create(User $actor): bool
    {
        return $actor->isContributor() || $this->adminCan($actor, 'tasks.update');
    }
}
