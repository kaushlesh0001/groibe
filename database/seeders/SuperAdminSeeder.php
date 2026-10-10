<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('groibe.admin.email');
        $password = config('groibe.admin.password');

        if (! $email || ! $password) {
            logger()->warning('ADMIN_EMAIL / ADMIN_PASSWORD not set: super admin not created.');

            return;
        }

        $user = User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->forceFill([
                'name' => config('groibe.admin.name'),
                'password' => $password,
                'role' => UserRole::Admin,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ])->save();
        }

        $user->syncRoles([AdminRole::SuperAdmin->value]);
    }
}
