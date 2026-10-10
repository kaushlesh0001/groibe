<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\OpenRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'open_role_id' => OpenRole::factory()->published(),
            'user_id' => User::factory()->contributor(),
            'status' => ApplicationStatus::Submitted,
            'motivation' => fake()->paragraph(),
            'relevant_experience' => fake()->paragraph(),
            'availability_note' => 'Ten hours a week.',
        ];
    }

    public function status(ApplicationStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'shortlisted_at' => in_array($status, [ApplicationStatus::Shortlisted, ApplicationStatus::Offered, ApplicationStatus::Accepted, ApplicationStatus::Declined], true) ? now() : null,
        ]);
    }
}
