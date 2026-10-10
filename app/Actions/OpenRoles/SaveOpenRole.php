<?php

namespace App\Actions\OpenRoles;

use App\Enums\OpenRoleStatus;
use App\Exceptions\ActionBlocked;
use App\Models\OpenRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Creates a draft role, or edits a draft or published one (spec 6.4, 7.4). Closed, filled and archived roles are history.
 * The slug is generated once and never changes after publication (spec 16).
 */
class SaveOpenRole
{
    private const FIELDS = [
        'project_id', 'title', 'summary', 'description', 'tasks_list', 'learning_outcomes', 'project_type_id',
        'work_mode', 'est_hours_min', 'est_hours_max', 'duration_label', 'compensation_type', 'compensation_note',
        'visibility', 'positions_needed', 'deadline', 'is_featured',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>|null  $skillIds  null leaves the skills untouched
     */
    public function __invoke(User $actor, array $data, ?OpenRole $role = null, ?array $skillIds = null): OpenRole
    {
        if ($role === null) {
            Gate::forUser($actor)->authorize('create', OpenRole::class);
        } else {
            Gate::forUser($actor)->authorize('update', $role);
        }

        return DB::transaction(function () use ($actor, $data, $role, $skillIds): OpenRole {
            $attributes = array_intersect_key($data, array_flip(self::FIELDS));

            if ($role === null) {
                $role = OpenRole::query()->create([
                    ...$attributes,
                    'slug' => $this->uniqueSlug((string) $data['title']),
                    'status' => OpenRoleStatus::Draft,
                    'created_by' => $actor->getKey(),
                ]);

                $event = 'created';
            } else {
                $role = OpenRole::query()->lockForUpdate()->findOrFail($role->getKey());

                if (! in_array($role->status, [OpenRoleStatus::Draft, OpenRoleStatus::Published], true)) {
                    throw ActionBlocked::immutable('A closed, filled or archived role');
                }

                $role->update($attributes);
                $event = 'updated';
            }

            if ($skillIds !== null) {
                $role->skills()->sync($skillIds);
            }

            activity('open_role')->causedBy($actor)->performedOn($role)->event($event)->log("Open role {$event}");

            return $role;
        });
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title) ?: 'role', 60, '');

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (OpenRole::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
