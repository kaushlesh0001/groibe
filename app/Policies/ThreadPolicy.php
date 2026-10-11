<?php

namespace App\Policies;

use App\Enums\FeatureFlag;
use App\Enums\ProjectStatus;
use App\Enums\ThreadType;
use App\Models\Thread;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;
use App\Services\FeatureFlags\FeatureFlags;

/**
 * Threads (spec 14). Reading is exactly `Thread::visibleTo`, so lists and policy can never disagree. Everything is closed
 * while the `messaging` flag is off. Replying adds a state rule for outsiders: a project's conversation goes read-only
 * for clients and contributors once the project is closed or cancelled; admins can always post.
 */
class ThreadPolicy
{
    use ChecksOrganizationAccess;

    /** Anyone signed in may open their inbox; what it lists is filtered by `visibleTo`. */
    public function viewAny(User $actor): bool
    {
        return $this->messagingOn();
    }

    public function view(User $actor, Thread $thread): bool
    {
        return Thread::query()->visibleTo($actor)->whereKey($thread->getKey())->exists();
    }

    /** Contributors (any review state) and clients can ask Groibe for help. Admins answer, they do not start support threads. */
    public function startSupport(User $actor): bool
    {
        return $this->messagingOn() && ($actor->isContributor() || $actor->isClient());
    }

    public function reply(User $actor, Thread $thread): bool
    {
        if (! $this->view($actor, $thread)) {
            return false;
        }

        if ($thread->type === ThreadType::Support) {
            return $actor->isAdmin() ? $actor->can('support.update') : $thread->created_by === $actor->getKey();
        }

        if ($actor->isAdmin()) {
            return $actor->can('threads.update');
        }

        $thread->loadMissing('project');

        return ! in_array($thread->project?->status, [ProjectStatus::Closed, ProjectStatus::Cancelled], true);
    }

    /** Admin-only notes inside a conversation. Nobody else may even set the flag. */
    public function postInternal(User $actor, Thread $thread): bool
    {
        if (! $actor->isAdmin() || ! $this->reply($actor, $thread)) {
            return false;
        }

        return $actor->can($thread->type === ThreadType::Support ? 'support.update' : 'threads.update');
    }

    private function messagingOn(): bool
    {
        return app(FeatureFlags::class)->enabled(FeatureFlag::Messaging);
    }
}
