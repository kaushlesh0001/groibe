<?php

namespace App\Actions\Contributors\Concerns;

use App\Enums\OnboardingState;
use App\Enums\UserStatus;
use App\Exceptions\IllegalTransition;
use App\Models\Profile;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Shared transition mechanics for spec 6.10 (with SCR-011): authorize, lock, validate the move,
 * persist the onboarding state and the matching users.status, then write the activity log entry.
 */
trait TransitionsContributor
{
    /**
     * @param  array<string, mixed>  $properties  extra data for the activity log entry (for example the reason)
     * @param  Closure(Profile, User): void|null  $guard  runs inside the transaction after the state check
     * @param  OnboardingState|null  $requiredFrom  the only state this Action may start from
     */
    protected function move(
        Profile $profile,
        OnboardingState $to,
        User $actor,
        string $ability,
        ?UserStatus $userStatus = null,
        array $properties = [],
        ?Closure $guard = null,
        ?OnboardingState $requiredFrom = null,
    ): Profile {
        Gate::forUser($actor)->authorize($ability, $profile);

        DB::transaction(function () use ($profile, $to, $actor, $userStatus, $properties, $guard, $requiredFrom): void {
            $locked = Profile::query()->lockForUpdate()->findOrFail($profile->getKey());
            $user = User::query()->findOrFail($locked->user_id);
            $from = $locked->onboarding_state ?? OnboardingState::Registered;

            // Approve and reinstate both end in `active`, so each Action pins the state it may start from.
            if (($requiredFrom !== null && $from !== $requiredFrom) || ! $from->canContributorTransitionTo($to)) {
                throw IllegalTransition::for('contributor', $from->value, $to->value);
            }

            if ($guard !== null) {
                $guard($locked, $user);
            }

            $locked->forceFill(['onboarding_state' => $to])->save();

            if ($userStatus !== null && $user->status !== $userStatus) {
                $user->forceFill(['status' => $userStatus])->save();
            }

            activity('contributor')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event($to->value)
                ->withProperties(['from' => $from->value, 'to' => $to->value, 'user_id' => $user->getKey(), ...$properties])
                ->log("Contributor moved from {$from->value} to {$to->value}");
        });

        return $profile->refresh();
    }
}
