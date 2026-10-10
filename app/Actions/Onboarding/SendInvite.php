<?php

namespace App\Actions\Onboarding;

use App\Enums\UserStatus;
use App\Exceptions\IllegalTransition;
use App\Models\User;
use App\Notifications\InviteNotification;
use Illuminate\Support\Facades\URL;

/** Sends (or resends) a signed activation link to an admin-created user (spec 6.11, 15.1). */
class SendInvite
{
    public function __invoke(User $user, ?User $sentBy = null): string
    {
        if ($user->status !== UserStatus::Pending || $user->password !== null) {
            throw IllegalTransition::for('invite', $user->status->value, 'invited');
        }

        $url = URL::temporarySignedRoute(
            'invite.show',
            now()->addDays((int) config('groibe.invites.expire_days')),
            ['token' => $user->uuid],
        );

        $user->notify(new InviteNotification($url));

        activity('auth')->causedBy($sentBy)->performedOn($user)->event('invite_sent')->log('Invite sent');

        return $url;
    }
}
