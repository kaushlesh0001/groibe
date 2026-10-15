<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Enums\OnboardingState;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Profile;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The form already offers only the roles this person may create; this is the check that cannot be skipped by editing the
     * request. Anyone but the super admin creates a pending account with no password: the person is invited to set their own.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        Gate::authorize('createRole', [User::class, UserRole::from((string) $data['role'])]);

        if (! auth()->user()?->can('manageCredentials', User::class)) {
            unset($data['password']);
            $data['status'] = UserStatus::Pending->value;
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $user = $this->getRecord();

        if (! $user instanceof User || $user->role === UserRole::Admin) {
            return;
        }

        Profile::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['onboarding_state' => OnboardingState::Registered],
        );

        $user->syncRoles([$user->role->value]);
    }
}
