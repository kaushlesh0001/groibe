<?php

namespace App\Services\Projects;

use App\Enums\AssignmentStatus;
use App\Enums\SowStatus;
use App\Models\Assignment;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What a client may learn about the people on a project (spec 12, 22, SCR-012): first name, role and
 * headline, only once the SOW is accepted and only when the admin has not hidden the team. Last names,
 * photos, contact details, rates and the state of invites never leave this class.
 */
class ClientTeam
{
    public function isHidden(Project $project): bool
    {
        return (bool) $project->hide_team;
    }

    public function sowAccepted(Project $project): bool
    {
        return $project->sows()->where('status', SowStatus::Accepted->value)->exists();
    }

    /**
     * Active and completed seats only: an invited, declined or removed contributor is not on the team.
     *
     * @return Collection<int, array{first_name: string, role: string, headline: string|null}>
     */
    public function members(Project $project): Collection
    {
        if ($this->isHidden($project) || ! $this->sowAccepted($project)) {
            return collect();
        }

        return Assignment::query()
            ->where('project_id', $project->getKey())
            ->whereIn('status', [AssignmentStatus::Active->value, AssignmentStatus::Completed->value])
            ->with(['user:id,name', 'user.profile:id,user_id,headline'])
            ->orderBy('id')
            ->get()
            ->map(fn (Assignment $assignment): array => [
                'first_name' => self::firstName($assignment->user->name),
                'role' => $assignment->role_title,
                'headline' => $assignment->user->profile?->headline,
            ]);
    }

    public static function firstName(string $name): string
    {
        return Str::of($name)->trim()->before(' ')->toString();
    }
}
