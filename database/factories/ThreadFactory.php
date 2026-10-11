<?php

namespace Database\Factories;

use App\Enums\ThreadType;
use App\Models\Project;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Thread>
 */
class ThreadFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'project_id' => null,
            'type' => ThreadType::Support,
            'subject' => fake()->sentence(4),
            'created_by' => User::factory()->contributor(),
        ];
    }

    public function forProject(?Project $project = null): static
    {
        return $this->state(fn () => [
            'type' => ThreadType::Project,
            'project_id' => $project ?? Project::factory(),
            'created_by' => User::factory()->admin(),
        ]);
    }
}
