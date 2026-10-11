<?php

namespace App\Services\Messaging;

use App\Enums\AssignmentStatus;
use App\Enums\OrgRole;
use App\Enums\ThreadType;
use App\Enums\UserStatus;
use App\Models\Assignment;
use App\Models\Message;
use App\Models\OrganizationUser;
use App\Models\Project;
use App\Models\Thread;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Collection;

/**
 * Who should hear about a new message (spec 14). It mirrors `Thread::visibleTo` for people, and never includes the
 * sender. An internal message reaches admins only.
 */
class ThreadAudience
{
    public function __construct(private NotificationDispatcher $dispatcher) {}

    /** @return Collection<int, User> */
    public function recipientsFor(Message $message): Collection
    {
        $message->loadMissing('thread.project');
        $thread = $message->thread;

        $recipients = $thread->type === ThreadType::Support
            ? $this->supportRecipients($thread, $message)
            : $this->projectRecipients($thread, $message);

        return $recipients
            ->where('status', UserStatus::Active)
            ->reject(fn (User $user): bool => $user->getKey() === $message->user_id)
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, User> */
    private function supportRecipients(Thread $thread, Message $message): Collection
    {
        if ($message->user_id === $thread->created_by) {
            return $this->dispatcher->adminsWith('support.view');
        }

        return $message->is_internal ? new Collection : new Collection(array_filter([$thread->creator]));
    }

    /** @return Collection<int, User> */
    private function projectRecipients(Thread $thread, Message $message): Collection
    {
        $project = $thread->project;

        $staff = $this->dispatcher->adminsWith('threads.view');
        $owner = $project->delivery_manager_id === null ? null : $staff->firstWhere('id', $project->delivery_manager_id);
        $recipients = $owner === null ? $staff : new Collection([$owner]);

        if ($message->is_internal) {
            return $recipients;
        }

        return $recipients
            ->concat($this->contributors($project))
            ->concat($this->clientManagers($project));
    }

    /** @return Collection<int, User> */
    private function contributors(Project $project): Collection
    {
        return User::query()
            ->whereIn('id', Assignment::query()
                ->where('project_id', $project->getKey())
                ->whereIn('status', [AssignmentStatus::Active->value, AssignmentStatus::Completed->value])
                ->select('user_id'))
            ->get();
    }

    /** @return Collection<int, User> */
    private function clientManagers(Project $project): Collection
    {
        return User::query()
            ->whereIn('id', OrganizationUser::query()
                ->where('organization_id', $project->organization_id)
                ->whereIn('org_role', [OrgRole::Owner->value, OrgRole::Manager->value])
                ->select('user_id'))
            ->get();
    }
}
