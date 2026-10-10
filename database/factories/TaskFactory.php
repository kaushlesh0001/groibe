<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'milestone_id' => Milestone::factory(),
            // Keep the denormalised project_id in step with the milestone's project.
            'project_id' => fn (array $attributes) => Milestone::query()->findOrFail($attributes['milestone_id'])->project_id,
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'estimate_hours' => 8,
            'status' => TaskStatus::Todo,
        ];
    }

    public function status(TaskStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
