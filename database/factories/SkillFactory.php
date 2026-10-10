<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'category_id' => Category::factory(),
            'is_active' => true,
        ];
    }
}
