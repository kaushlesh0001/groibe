<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\Users\UserResource;

/**
 * Daily report to delivery managers of people who were invited (created by an admin or an import, no password yet) more
 * than 14 days ago and have not activated (SCR-034). It is for the bell only and it deletes nobody: the people are still
 * there to be sent a new invite.
 */
class StaleInvitesReport extends GroibeNotification
{
    /** @param  list<string>  $names  the first few, oldest invite first; `$total` is the real count */
    public function __construct(public int $total, public array $names, public int $days) {}

    public function category(): string
    {
        return 'onboarding';
    }

    /** @return list<string> */
    public function availableChannels(): array
    {
        return ['database'];
    }

    public function headline(): string
    {
        $who = $this->total === 1 ? '1 person has' : "{$this->total} people have";

        return "{$who} not activated an invite sent over {$this->days} days ago";
    }

    public function url(): string
    {
        return UserResource::getUrl('index');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $more = $this->total - count($this->names);

        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => implode(', ', $this->names).($more > 0 ? " and {$more} more. Resend their invites." : '. Resend their invite.'),
            'url' => $this->url(),
        ];
    }
}
