<?php

namespace Database\Factories;

use App\Enums\Availability;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'headline' => fake()->jobTitle(),
            'bio' => fake()->paragraph(),
            'city' => fake()->randomElement(['Kolkata', 'Pune', 'Bengaluru', 'Delhi', 'Mumbai']),
            'country' => 'India',
            'availability' => Availability::Available,
            'hours_per_week' => 15,
            'work_modes' => ['remote'],
            'is_public' => false,
        ];
    }

    public function public(): static
    {
        return $this->state(fn () => ['is_public' => true, 'slug' => fake()->unique()->slug(3)]);
    }
}
