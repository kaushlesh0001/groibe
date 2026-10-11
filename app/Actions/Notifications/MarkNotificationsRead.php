<?php

namespace App\Actions\Notifications;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/** Marks notifications read (spec 14). Only the person's own rows can be touched: other people's ids simply match nothing. */
class MarkNotificationsRead
{
    /** One notification. Returns it, or null when the person has no such notification. */
    public function one(User $user, string $id): ?DatabaseNotification
    {
        $notification = $user->notifications()->whereKey($id)->first();

        $notification?->markAsRead();

        return $notification;
    }

    /** Everything unread. Returns how many were marked. */
    public function all(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }
}
