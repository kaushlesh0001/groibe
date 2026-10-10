<?php

namespace Database\Factories;

use App\Models\ProjectType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectType>
 */
class ProjectTypeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
