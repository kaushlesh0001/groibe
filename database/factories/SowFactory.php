<?php

namespace Database\Factories;

use App\Enums\SowStatus;
use App\Models\Project;
use App\Models\Sow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sow>
 */
class SowFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'version' => 1,
            'status' => SowStatus::Draft,
            'title' => 'Statement of Work',
            'body_html' => '<p>Scope of work.</p>',
            'body_variables' => [],
            'total_value' => 150000,
            'currency' => 'INR',
            'payment_terms' => '50% on acceptance, 50% on delivery.',
        ];
    }

    public function status(SowStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'sent_at' => $status === SowStatus::Draft ? null : now(),
        ]);
    }
}
