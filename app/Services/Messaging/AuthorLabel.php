<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Models\Thread;
use App\Models\User;

/**
 * How a message's author is named to a given reader. Clients see contributors by first name only, or as "Project team"
 * when the project hides its team (spec 12). Contributors never see a client person's name or the organization: a client
 * author is just "Client" (SCR-044). Outsiders see Groibe staff as "Groibe". The reader's own messages say "You". Needs
 * `thread.project.organization` and `user` loaded.
 */
class AuthorLabel
{
    /** What a contributor sees in place of any client person (SCR-044). */
    public const CLIENT = 'Client';

    public function for(Message $message, Thread $thread, User $viewer): string
    {
        $author = $message->user;

        if ($author->getKey() === $viewer->getKey()) {
            return 'You';
        }

        $first = explode(' ', trim($author->name))[0];

        if ($author->isAdmin()) {
            return $viewer->isAdmin() ? $author->name : $first.' (Groibe)';
        }

        if ($author->isClient()) {
            if ($viewer->isContributor()) {
                return self::CLIENT;
            }

            $organization = $thread->project?->organization?->name;

            return $organization === null ? $first : "{$first}, {$organization}";
        }

        if ($viewer->isClient() && $thread->project?->hide_team) {
            return 'Project team';
        }

        return $first;
    }

    /** The name above a task comment on a contributor's task page. A client person is never named there (SCR-044). */
    public function forTaskComment(User $author): string
    {
        return $author->isClient() ? self::CLIENT : $author->name;
    }
}
