<?php

namespace Database\Factories;

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Milestone>
 */
class MilestoneFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'sort' => 0,
            'status' => MilestoneStatus::Planned,
        ];
    }

    public function status(MilestoneStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'shared_at' => $status->isClientVisible() ? now() : null,
        ]);
    }
}
