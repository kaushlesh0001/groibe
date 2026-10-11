<?php

namespace App\Services\OpenRoles;

use App\Enums\Availability;
use App\Models\OpenRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Rule-based recommendations for the contributor dashboard (spec 2.3, 11): roles the contributor can see and has not
 * applied to, that share at least one of their skills and fit their stated hours. The most shared skills come first.
 * No scoring model: the count of shared skills is shown as is.
 */
class RoleRecommender
{
    /** @return Collection<int, OpenRole> */
    public function for(User $user, int $limit = 5): Collection
    {
        $user->loadMissing('profile');

        if (! $user->isActiveContributor() || $user->profile?->availability === Availability::Unavailable) {
            return new Collection;
        }

        $skillIds = $user->skills()->pluck('skills.id')->all();

        if ($skillIds === []) {
            return new Collection;
        }

        $hours = $user->profile?->hours_per_week;

        return OpenRole::query()
            ->visibleTo($user)
            ->where(fn (Builder $open) => $open->whereNull('open_roles.deadline')->orWhere('open_roles.deadline', '>=', now()->toDateString()))
            ->whereDoesntHave('applications', fn (Builder $applications) => $applications->where('applications.user_id', $user->getKey()))
            ->whereHas('skills', fn (Builder $skills) => $skills->whereIn('skills.id', $skillIds))
            ->when($hours !== null, fn (Builder $q) => $q->where(fn (Builder $fit) => $fit->whereNull('open_roles.est_hours_min')->orWhere('open_roles.est_hours_min', '<=', $hours)))
            ->withCount(['skills as matching_skills_count' => fn (Builder $skills) => $skills->whereIn('skills.id', $skillIds)])
            ->orderByDesc('matching_skills_count')
            ->orderByDesc('open_roles.published_at')
            ->limit($limit)
            ->get();
    }
}
