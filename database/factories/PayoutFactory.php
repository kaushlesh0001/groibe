<?php

namespace Database\Factories;

use App\Enums\PayoutStatus;
use App\Models\Assignment;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'assignment_id' => Assignment::factory()->active(),
            'user_id' => fn (array $attributes) => Assignment::query()->find($attributes['assignment_id'])->user_id,
            'project_id' => fn (array $attributes) => Assignment::query()->find($attributes['assignment_id'])->project_id,
            'amount' => 10000,
            'currency' => 'INR',
            'status' => PayoutStatus::Planned,
            'created_by' => User::factory()->admin(),
        ];
    }

    public function status(PayoutStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'approved_at' => in_array($status, [PayoutStatus::Approved, PayoutStatus::Paid], true) ? now() : null,
            'paid_at' => $status === PayoutStatus::Paid ? now() : null,
            'reference' => $status === PayoutStatus::Paid ? 'UTR'.fake()->numerify('#########') : null,
        ]);
    }
}
