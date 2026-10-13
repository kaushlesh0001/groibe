<?php

namespace App\Services\Notifications;

use App\Enums\OrgRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\GroibeNotification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Single entry point for platform notifications (spec 14) so the delivery provider can change later.
 * Each recipient's per-category choices come from NotificationPreferences. Locked categories (security) always
 * get both channels, and a category switched off on both sends nothing.
 */
class NotificationDispatcher
{
    public function __construct(private NotificationPreferences $preferences) {}

    /** @param  iterable<User>|User  $recipients */
    public function send(iterable|User $recipients, GroibeNotification $notification): void
    {
        $recipients = $recipients instanceof User ? [$recipients] : $recipients;

        foreach ($recipients as $user) {
            if ($user->status === UserStatus::Suspended) {
                continue;
            }

            $channels = array_values(array_intersect(
                $this->preferences->channelsFor($user, $notification->category()),
                $notification->availableChannels(),
            ));

            // Everything switched off for this category, or off on the only channel this notification may use: nothing to send.
            if ($channels === []) {
                continue;
            }

            $copy = clone $notification;
            $copy->channels = $channels;
            $user->notify($copy);
        }
    }

    /** Owners and managers of the organization, plus an optional extra user such as the brief submitter. */
    public function toOrganization(Organization $organization, GroibeNotification $notification, ?User $alsoNotify = null): void
    {
        $recipients = $this->organizationManagers($organization);

        if ($alsoNotify !== null && ! $recipients->contains('id', $alsoNotify->id)) {
            $recipients->push($alsoNotify);
        }

        $this->send($recipients, $notification);
    }

    /** Active admins holding the permission, or only the preferred admin when given and still eligible. */
    public function toAdmins(string $permission, GroibeNotification $notification, ?User $preferred = null): void
    {
        $admins = $this->adminsWith($permission);

        if ($preferred !== null && $admins->contains('id', $preferred->id)) {
            $admins = $admins->where('id', $preferred->id);
        }

        $this->send($admins, $notification);
    }

    /** @return Collection<int, User> */
    public function organizationManagers(Organization $organization): Collection
    {
        return $organization->users()
            ->wherePivotIn('org_role', [OrgRole::Owner->value, OrgRole::Manager->value])
            ->where('users.status', UserStatus::Active->value)
            ->get();
    }

    /** @return Collection<int, User> */
    public function adminsWith(string $permission): Collection
    {
        return User::permission($permission)
            ->where('status', UserStatus::Active->value)
            ->get();
    }
}
