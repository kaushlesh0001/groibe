<?php

namespace Database\Factories;

use App\Models\ReputationFact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReputationFact>
 */
class ReputationFactFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->contributor(),
            'projects_completed' => 0,
            'tasks_approved_first_pass' => 0,
            'tasks_total' => 0,
            'tasks_with_due_date' => 0,
            'on_time_tasks' => 0,
            'reviews_count' => 0,
            'avg_rating' => null,
            'verified_skills_count' => 0,
            'recomputed_at' => now(),
        ];
    }
}
