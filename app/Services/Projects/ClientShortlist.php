<?php

namespace App\Services\Projects;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Project;
use App\Models\Skill;
use Illuminate\Support\Collection;

/**
 * What a client may learn about applicants (spec 2.2, 12, 22): only when the admin switched on
 * `client_can_view_shortlist` for the project, only after the SOW is accepted (contributor identity is not shown
 * earlier), and never when the team is hidden. Only shortlisted and offered applicants appear, with first name, the
 * role they applied for, headline and skill names. Last names, contact details, photos, answers, expected amounts,
 * admin notes and everyone else who applied never leave this class.
 */
class ClientShortlist
{
    public function __construct(private ClientTeam $team) {}

    public function visibleFor(Project $project): bool
    {
        return (bool) $project->client_can_view_shortlist
            && ! $this->team->isHidden($project)
            && $this->team->sowAccepted($project);
    }

    /** @return Collection<int, array{first_name: string, role: string, headline: string|null, skills: array<int, string>}> */
    public function entries(Project $project): Collection
    {
        if (! $this->visibleFor($project)) {
            return collect();
        }

        return Application::query()
            ->whereIn('status', [ApplicationStatus::Shortlisted->value, ApplicationStatus::Offered->value])
            ->whereHas('openRole', fn ($roles) => $roles->where('open_roles.project_id', $project->getKey()))
            ->with(['openRole:id,title,project_id', 'user:id,name', 'user.profile:id,user_id,headline', 'user.skills:id,name'])
            ->orderBy('applications.id')
            ->get()
            ->map(fn (Application $application): array => [
                'first_name' => ClientTeam::firstName($application->user->name),
                'role' => $application->openRole->title,
                'headline' => $application->user->profile?->headline,
                'skills' => $application->user->skills->map(fn (Skill $skill): string => $skill->name)->take(5)->values()->all(),
            ]);
    }
}
