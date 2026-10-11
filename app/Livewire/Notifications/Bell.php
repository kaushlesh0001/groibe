<?php

namespace App\Livewire\Notifications;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** The bell (spec 14): a link to the notification centre with the unread count, refreshed every minute while visible. */
class Bell extends Component
{
    public function render()
    {
        return view('livewire.notifications.bell', [
            'count' => Auth::user()->unreadNotifications()->count(),
        ]);
    }
}
