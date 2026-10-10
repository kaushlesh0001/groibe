<?php

namespace App\Actions\Onboarding;

use App\Enums\OnboardingState;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

/**
 * Creates a self-registered account (contributor or client) with an empty profile.
 * Onboarding steps and organization creation arrive in later passes (spec 6.10, 6.11).
 */
class RegisterAccount
{
    public function __invoke(UserRole $role, string $name, string $email, string $password): User
    {
        if ($role === UserRole::Admin) {
            throw new \InvalidArgumentException('Admin accounts cannot self-register.');
        }

        $user = DB::transaction(function () use ($role, $name, $email, $password): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => $role,
                'status' => UserStatus::Pending,
            ]);

            Profile::query()->create([
                'user_id' => $user->id,
                'onboarding_state' => OnboardingState::Registered,
            ]);

            $user->assignRole($role->value);

            return $user;
        });

        activity('auth')->causedBy($user)->performedOn($user)->event('registered')->log("Registered as {$role->value}");

        event(new Registered($user));

        return $user;
    }
}
