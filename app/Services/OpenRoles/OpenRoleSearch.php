<?php

namespace App\Services\OpenRoles;

use App\Enums\CompensationType;
use App\Enums\WorkMode;
use App\Models\OpenRole;
use App\Models\ProjectType;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Filters and search shared by the public /opportunities page and the contributor Discover page (spec 10.2, 11).
 * Callers pass a query already narrowed by OpenRole::visibleTo(), so a filter can never widen what a viewer may see.
 */
class OpenRoleSearch
{
    /** Validation rules for the filter query string. @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'skill' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:100'],
            'work_mode' => ['nullable', 'in:'.implode(',', array_column(WorkMode::cases(), 'value'))],
            'compensation' => ['nullable', 'in:'.implode(',', array_column(CompensationType::cases(), 'value'))],
            'duration' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * Keeps only the filter values that pass the rules. For pages that must never fail on a stale or edited link,
     * such as a bookmarked filter whose option no longer exists: the bad value is dropped and the rest still apply.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $input = array_intersect_key($input, self::rules());
        $invalid = array_keys(Validator::make($input, self::rules())->errors()->messages());

        return array_diff_key($input, array_flip($invalid));
    }

    /**
     * @param  Builder<OpenRole>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<OpenRole>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $term = trim((string) ($filters['q'] ?? ''));

        return $query
            ->when($term !== '', fn (Builder $q) => $this->search($q, $term))
            ->when(filled($filters['skill'] ?? null), fn (Builder $q) => $q->whereHas(
                'skills',
                fn (Builder $skills) => $skills->where('skills.slug', $filters['skill'])->where('skills.is_active', true),
            ))
            ->when(filled($filters['type'] ?? null), fn (Builder $q) => $q->whereHas(
                'projectType',
                fn (Builder $types) => $types->where('project_types.slug', $filters['type']),
            ))
            ->when(filled($filters['work_mode'] ?? null), fn (Builder $q) => $q->where('open_roles.work_mode', $filters['work_mode']))
            ->when(filled($filters['compensation'] ?? null), fn (Builder $q) => $q->where('open_roles.compensation_type', $filters['compensation']))
            ->when(filled($filters['duration'] ?? null), fn (Builder $q) => $q->where('open_roles.duration_label', $filters['duration']));
    }

    /**
     * Choices for the filter drop-downs, limited to what the viewer can actually find.
     *
     * @param  Builder<OpenRole>  $visible
     * @return array{skills: Collection<int, Skill>, types: Collection<int, ProjectType>, durations: Collection<int, string>}
     */
    public function options(Builder $visible): array
    {
        $ids = (clone $visible)->select('open_roles.id');

        return [
            'skills' => Skill::query()->active()
                ->whereIn('id', DB::table('open_role_skills')->select('skill_id')->whereIn('open_role_id', $ids))
                ->orderBy('name')->get(['id', 'name', 'slug']),
            'types' => ProjectType::query()
                ->whereIn('id', (clone $visible)->whereNotNull('open_roles.project_type_id')->select('open_roles.project_type_id'))
                ->orderBy('name')->get(['id', 'name', 'slug']),
            'durations' => (clone $visible)->whereNotNull('open_roles.duration_label')
                ->distinct()->orderBy('open_roles.duration_label')->pluck('open_roles.duration_label'),
        ];
    }

    /** FULLTEXT on title and summary (spec 7.10) on MySQL, a LIKE match elsewhere (tests run on SQLite). @param Builder<OpenRole> $query */
    private function search(Builder $query, string $term): void
    {
        if (DB::getDriverName() === 'mysql') {
            $query->whereFullText(['open_roles.title', 'open_roles.summary'], $term);

            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(fn (Builder $inner) => $inner->where('open_roles.title', 'like', $like)->orWhere('open_roles.summary', 'like', $like));
    }
}
