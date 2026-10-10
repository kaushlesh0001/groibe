<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->sentence(3);
        $code = 'GRB-'.now()->year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT);

        return [
            'organization_id' => Organization::factory()->verified(),
            'code' => $code,
            'title' => $title,
            'slug' => Str::slug($code.' '.$title),
            'summary' => fake()->paragraph(),
            'scope_summary' => fake()->paragraph(),
            'status' => ProjectStatus::Draft,
            'contract_value' => 150000,
            'currency' => 'INR',
        ];
    }

    public function status(ProjectStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
