<?php

namespace Database\Factories;

use App\Enums\AssignmentStatus;
use App\Enums\RateType;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assignment>
 */
class AssignmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory()->contributor(),
            'role_title' => fake()->jobTitle(),
            'status' => AssignmentStatus::Invited,
            'rate_type' => RateType::Fixed,
            'agreed_amount' => 40000,
            'currency' => 'INR',
            'invited_at' => now(),
        ];
    }

    public function status(AssignmentStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'accepted_at' => in_array($status, [AssignmentStatus::Active, AssignmentStatus::Completed, AssignmentStatus::Removed], true) ? now() : null,
            'completed_at' => $status === AssignmentStatus::Completed ? now() : null,
        ]);
    }

    public function active(): static
    {
        return $this->status(AssignmentStatus::Active);
    }
}
