<?php

namespace App\Actions\Onboarding;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\IllegalTransition;
use App\Models\User;

/**
 * Redeems an invite: sets the password and verifies the email (the invite proves mailbox ownership).
 * Clients and admins become active. Contributors stay pending until admin review (spec 6.10, Pass 3).
 */
class ActivateInvitedUser
{
    public function __invoke(User $user, string $password): User
    {
        if ($user->status !== UserStatus::Pending || $user->password !== null) {
            throw IllegalTransition::for('invite', $user->status->value, 'activated');
        }

        $user->forceFill([
            'password' => $password,
            'email_verified_at' => now(),
            'status' => $user->role === UserRole::Contributor ? UserStatus::Pending : UserStatus::Active,
        ])->save();

        activity('auth')->causedBy($user)->performedOn($user)->event('invite_activated')->log('Invite activated');

        return $user;
    }
}
