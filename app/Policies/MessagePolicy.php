<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * A message is readable when its thread is, except internal messages, which only admins read (spec 14). Messages are
 * never edited or deleted by their authors in v1, so those abilities are not granted.
 */
class MessagePolicy
{
    use ChecksOrganizationAccess;

    public function view(User $actor, Message $message): bool
    {
        if ($message->is_internal && ! $actor->isAdmin()) {
            return false;
        }

        $message->loadMissing('thread');

        return $actor->can('view', $message->thread);
    }
}
