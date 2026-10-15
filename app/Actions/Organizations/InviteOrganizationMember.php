<?php

namespace App\Actions\Organizations;

use App\Actions\Onboarding\SendInvite;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The organization owner invites a colleague (spec 12, SCR-002): an unactivated client account is created and linked to this
 * organization, and the usual signed activation link is emailed. An email that already has an account anywhere is refused, never
 * moved or linked: pulling an existing account into an organization is the admin's call. Owners are made by an admin only.
 */
class InviteOrganizationMember
{
    public const REFUSED_EXISTING = 'That email address already has an account. Ask Groibe to add that person to your organization.';

    public function __construct(private SendInvite $sendInvite) {}

    public function __invoke(Organization $organization, User $actor, string $name, string $email, OrgRole $role): User
    {
        Gate::forUser($actor)->authorize('inviteMember', $organization);

        if (! in_array($role, [OrgRole::Manager, OrgRole::Viewer], true)) {
            throw ActionBlocked::guardFailed('You can invite a manager or a viewer. Ask Groibe to add another owner.');
        }

        $email = strtolower(trim($email));

        if (User::withTrashed()->where('email', $email)->exists()) {
            throw ActionBlocked::guardFailed(self::REFUSED_EXISTING);
        }

        try {
            $user = DB::transaction(function () use ($organization, $name, $email, $role): User {
                $user = User::query()->create([
                    'name' => trim($name),
                    'email' => $email,
                    'role' => UserRole::Client,
                    'status' => UserStatus::Pending,
                ]);
                $user->assignRole(UserRole::Client->value);

                Profile::query()->create(['user_id' => $user->getKey(), 'onboarding_state' => OnboardingState::Registered]);

                $organization->users()->attach($user->getKey(), ['org_role' => $role->value]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ActionBlocked::guardFailed(self::REFUSED_EXISTING);
        }

        activity('organization')->causedBy($actor)->performedOn($organization)->event('member_invited')
            ->withProperties(['role' => $role->value])->log('Team member invited');

        ($this->sendInvite)($user, $actor);

        return $user;
    }
}
