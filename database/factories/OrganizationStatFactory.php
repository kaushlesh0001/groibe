<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationStat>
 */
class OrganizationStatFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'projects_completed' => 0,
            'reviews_count' => 0,
            'avg_rating' => null,
            'recomputed_at' => now(),
        ];
    }
}
