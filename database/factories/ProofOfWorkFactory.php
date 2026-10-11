<?php

namespace Database\Factories;

use App\Enums\ClientDisplay;
use App\Enums\ProofStatus;
use App\Enums\ProofVisibility;
use App\Models\Project;
use App\Models\ProofOfWork;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProofOfWork>
 */
class ProofOfWorkFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->contributor(),
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
            'role_title' => fake()->jobTitle(),
            'summary' => fake()->sentence(12),
            'contribution_text' => fake()->paragraph(),
            'outcome_metrics' => [],
            'visibility' => ProofVisibility::Private,
            'status' => ProofStatus::Draft,
            'client_display' => ClientDisplay::Anonymous,
        ];
    }

    /** Published, public and sluggable. The project's showcase consent still decides whether the public sees it. */
    public function published(): static
    {
        return $this->state(fn () => [
            'status' => ProofStatus::Published,
            'visibility' => ProofVisibility::Public,
            'slug' => Str::slug(fake()->unique()->sentence(3)).'-'.Str::lower(Str::random(6)),
            'published_at' => now(),
        ]);
    }

    public function status(ProofStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
