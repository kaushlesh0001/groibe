<?php

namespace App\Actions\Contributors;

use App\Enums\SkillLevel;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\SkillUser;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Replaces a contributor's skills with the given set (spec 7.2). A level change on an already
 * verified skill clears the verification, so admins re-verify what they have not seen.
 */
class SyncContributorSkills
{
    /** @param  array<int, string|SkillLevel>  $levels  skill id => level; skills not listed are removed */
    public function __invoke(Profile $profile, array $levels, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $profile);

        $user = $profile->user()->firstOrFail();
        $activeIds = Skill::query()->active()->whereIn('id', array_keys($levels))->pluck('id')->all();
        $existing = SkillUser::query()->where('user_id', $user->getKey())->get()->keyBy('skill_id');

        $payload = [];

        foreach ($activeIds as $skillId) {
            $level = $levels[$skillId] instanceof SkillLevel ? $levels[$skillId] : SkillLevel::from($levels[$skillId]);
            $previous = $existing->get($skillId);
            $keep = $previous !== null && $previous->level === $level;

            $payload[$skillId] = $keep
                ? ['level' => $level->value]
                : ['level' => $level->value, 'is_verified' => false, 'verified_by' => null, 'verified_at' => null];
        }

        $user->skills()->sync($payload);

        activity('contributor')->causedBy($actor)->performedOn($profile)->event('skills_saved')
            ->withProperties(['skill_ids' => array_keys($payload)])->log('Skills saved');
    }
}
