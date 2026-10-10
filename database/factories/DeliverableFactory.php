<?php

namespace Database\Factories;

use App\Models\Deliverable;
use App\Models\Milestone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deliverable>
 */
class DeliverableFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'milestone_id' => Milestone::factory(),
            'title' => fake()->sentence(3),
            'summary' => fake()->sentence(),
        ];
    }

    public function shared(): static
    {
        return $this->state(fn () => ['shared_at' => now()]);
    }
}
