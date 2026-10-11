<?php

namespace App\Services\Messaging;

use App\Filament\Admin\Resources\Threads\ThreadResource;
use App\Models\Thread;
use App\Models\User;

/** Where each kind of person opens a conversation. */
class ThreadLinks
{
    public function for(Thread $thread, User $user): string
    {
        return match (true) {
            $user->isAdmin() => ThreadResource::getUrl('view', ['record' => $thread]),
            $user->isClient() => route('client.messages.show', $thread),
            default => route('contributor.messages.show', $thread),
        };
    }

    public function inbox(User $user): string
    {
        return $user->isClient() ? route('client.messages.index') : route('contributor.messages.index');
    }
}
