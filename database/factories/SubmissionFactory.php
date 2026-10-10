<?php

namespace Database\Factories;

use App\Enums\SubmissionStatus;
use App\Models\Assignment;
use App\Models\Submission;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'assignment_id' => Assignment::factory(),
            // The submitter is the assignment's contributor.
            'user_id' => fn (array $attributes) => Assignment::query()->findOrFail($attributes['assignment_id'])->user_id,
            'note' => fake()->sentence(),
            'status' => SubmissionStatus::Submitted,
            'version' => 1,
        ];
    }

    public function status(SubmissionStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'reviewed_at' => $status === SubmissionStatus::Submitted ? null : now(),
        ]);
    }
}
