<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Enums\OnboardingState;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Profile;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

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
