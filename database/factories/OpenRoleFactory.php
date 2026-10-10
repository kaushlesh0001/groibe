<?php

namespace Database\Factories;

use App\Enums\CompensationType;
use App\Enums\OpenRoleStatus;
use App\Enums\OpenRoleVisibility;
use App\Enums\WorkMode;
use App\Models\OpenRole;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OpenRole>
 */
class OpenRoleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->unique()->jobTitle();

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'summary' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'tasks_list' => ['Research the market', 'Write up findings'],
            'learning_outcomes' => ['Client-ready reporting'],
            'work_mode' => WorkMode::Remote,
            'est_hours_min' => 5,
            'est_hours_max' => 10,
            'duration_label' => '4 weeks',
            'compensation_type' => CompensationType::Paid,
            'compensation_note' => 'Fixed fee on delivery.',
            'visibility' => OpenRoleVisibility::MembersOnly,
            'positions_needed' => 1,
            'status' => OpenRoleStatus::Draft,
            'is_featured' => false,
        ];
    }

    public function status(OpenRoleStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'published_at' => $status === OpenRoleStatus::Draft ? null : now(),
            'closed_at' => in_array($status, [OpenRoleStatus::Closed, OpenRoleStatus::Filled, OpenRoleStatus::Archived], true) ? now() : null,
        ]);
    }

    public function published(): static
    {
        return $this->status(OpenRoleStatus::Published);
    }

    public function visibility(OpenRoleVisibility $visibility): static
    {
        return $this->state(fn () => ['visibility' => $visibility]);
    }

    public function public(): static
    {
        return $this->visibility(OpenRoleVisibility::Public);
    }

    public function inviteOnly(): static
    {
        return $this->visibility(OpenRoleVisibility::InviteOnly);
    }

    public function forProject(?Project $project = null): static
    {
        return $this->state(fn () => ['project_id' => $project?->id ?? Project::factory()]);
    }
}
