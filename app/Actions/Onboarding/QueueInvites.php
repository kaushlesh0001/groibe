<?php

namespace App\Actions\Onboarding;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\SendQueuedInvite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Bulk "Send invites" (spec 15.1): queues a signed activation link for each person who is still `pending` with no password.
 * Emails are spread out, at most `groibe.invites.per_minute` a minute, so a large batch never floods the mail host.
 * People the actor may not update, or who are not waiting for an invite, are skipped and counted.
 */
class QueueInvites
{
    /**
     * @param  iterable<User>  $users
     * @return array{queued: int, skipped: int}
     */
    public function __invoke(iterable $users, User $actor): array
    {
        $gate = Gate::forUser($actor);
        $perMinute = max(1, (int) config('groibe.invites.per_minute'));
        $queued = 0;
        $skipped = 0;

        foreach ($users as $user) {
            if (! $this->waitingForInvite($user) || $gate->denies('update', $user)) {
                $skipped++;

                continue;
            }

            SendQueuedInvite::dispatch($user->getKey(), $actor->getKey())->delay(now()->addSeconds(intdiv($queued * 60, $perMinute)));
            $queued++;
        }

        if ($queued > 0) {
            activity('auth')->causedBy($actor)->event('invites_queued')->withProperties(['count' => $queued])->log('Invites queued');
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    private function waitingForInvite(User $user): bool
    {
        return $user->status === UserStatus::Pending
            && $user->password === null
            && in_array($user->role, [UserRole::Contributor, UserRole::Client], true);
    }
}
