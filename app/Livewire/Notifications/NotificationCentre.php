<?php

namespace App\Livewire\Notifications;

use App\Actions\Notifications\MarkNotificationsRead;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/** The notification centre (spec 14). Not named Index: that would register as `notifications`, which Filament already uses.: the person's own notifications, newest first, with mark read and mark all read. */
#[Title('Notifications')]
class NotificationCentre extends Component
{
    use WithPagination;

    public function open(string $id): mixed
    {
        $notification = app(MarkNotificationsRead::class)->one(Auth::user(), $id);

        abort_if($notification === null, 404);

        $url = $notification->data['url'] ?? null;

        return is_string($url) && $this->isOurs($url) ? $this->redirect($url, navigate: true) : null;
    }

    public function markRead(string $id): void
    {
        app(MarkNotificationsRead::class)->one(Auth::user(), $id);
    }

    public function markAllRead(): void
    {
        app(MarkNotificationsRead::class)->all(Auth::user());
        $this->resetPage();
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.notifications.notification-centre', [
            'notifications' => $user->notifications()->paginate(20),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /** Only follow links that point back at this site. */
    private function isOurs(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host === null
            ? str_starts_with($url, '/') && ! str_starts_with($url, '//')
            : in_array($host, [request()->getHost(), parse_url((string) config('app.url'), PHP_URL_HOST)], true);
    }
}
