<?php

namespace App\Services\Messaging;

use App\Models\Thread;
use App\Models\User;

/** Number of conversations holding something the person has not read, for the navigation badge. Remembered for the request. */
class UnreadMessages
{
    /** @var array<int, int> */
    private array $counts = [];

    public function count(User $user): int
    {
        return $this->counts[$user->getKey()] ??= Thread::query()->visibleTo($user)->havingUnread($user)->count();
    }
}
