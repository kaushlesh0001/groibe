<?php

namespace Database\Factories;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'industry' => fake()->randomElement(['Manufacturing', 'Logistics', 'Healthcare']),
            'size_band' => '51-200',
            'city' => 'Kolkata',
            'country' => 'India',
            'status' => OrganizationStatus::Pending,
            'can_submit_briefs' => false,
            'is_public' => false,
            'is_featured' => false,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => ['status' => OrganizationStatus::Verified, 'verified_at' => now()]);
    }
}
