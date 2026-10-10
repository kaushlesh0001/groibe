<?php

namespace Database\Factories;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= 'password',
            'role' => UserRole::Contributor,
            'status' => UserStatus::Active,
            'timezone' => 'Asia/Kolkata',
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    /** An admin-created or imported user awaiting an invite: no password, not yet verified. */
    public function invited(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Pending, 'password' => null, 'email_verified_at' => null]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Pending]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }

    public function contributor(): static
    {
        return $this->state(fn () => ['role' => UserRole::Contributor]);
    }

    public function client(): static
    {
        return $this->state(fn () => ['role' => UserRole::Client]);
    }

    public function admin(AdminRole $adminRole = AdminRole::SuperAdmin): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin])
            ->afterCreating(fn (User $user) => $user->assignRole($adminRole->value));
    }
}
