<?php

namespace App\Actions\Messages;

use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Moves the person's read marker on a thread to now (spec 14 unread counts). Only people who can see the thread get one. */
class MarkThreadRead
{
    public function __invoke(Thread $thread, User $actor): void
    {
        Gate::forUser($actor)->authorize('view', $thread);

        $now = now()->format('Y-m-d H:i:s.u');

        $updated = DB::table('thread_participants')
            ->where('thread_id', $thread->getKey())
            ->where('user_id', $actor->getKey())
            ->update(['last_read_at' => $now, 'updated_at' => $now]);

        if ($updated === 0) {
            DB::table('thread_participants')->insertOrIgnore([
                'thread_id' => $thread->getKey(),
                'user_id' => $actor->getKey(),
                'last_read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
