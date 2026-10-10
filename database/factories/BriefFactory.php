<?php

namespace Database\Factories;

use App\Enums\BriefStatus;
use App\Enums\Confidentiality;
use App\Models\Brief;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Brief>
 */
class BriefFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory()->verified(),
            'title' => fake()->sentence(4),
            'problem' => fake()->paragraph(),
            'desired_outcome' => fake()->paragraph(),
            'budget_band' => fake()->randomElement(array_keys(config('groibe.briefs.budget_bands'))),
            'timeline_band' => fake()->randomElement(array_keys(config('groibe.briefs.timeline_bands'))),
            'confidentiality' => Confidentiality::Standard,
            'status' => BriefStatus::Draft,
        ];
    }

    public function status(BriefStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'submitted_at' => $status === BriefStatus::Draft ? null : now(),
        ]);
    }
}
