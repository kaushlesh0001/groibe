<?php

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectCodeGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Creates a draft project with a unique GRB-YYYY-NNNN code. The unique index is the guarantee; collisions retry. */
class CreateProject
{
    private const ATTEMPTS = 5;

    public function __construct(private ProjectCodeGenerator $codes) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $skillIds
     */
    public function __invoke(array $attributes, User $actor, array $skillIds = []): Project
    {
        Gate::forUser($actor)->authorize('create', Project::class);

        // Three attempts cover a database deadlock when this is the outermost transaction (two admins creating
        // the first project of a year at once). Unique-code collisions are retried inside createWithUniqueCode.
        return DB::transaction(function () use ($attributes, $actor, $skillIds): Project {
            $project = $this->createWithUniqueCode($attributes);

            if ($skillIds !== []) {
                $project->skills()->sync($skillIds);
            }

            activity('project')
                ->causedBy($actor)
                ->performedOn($project)
                ->event('created')
                ->withProperties(['code' => $project->code, 'brief_id' => $project->brief_id])
                ->log("Project {$project->code} created");

            return $project;
        }, 3);
    }

    /** @param  array<string, mixed>  $attributes */
    private function createWithUniqueCode(array $attributes): Project
    {
        for ($attempt = 1; ; $attempt++) {
            $code = $this->codes->next();

            try {
                return DB::transaction(fn (): Project => Project::create([
                    ...$attributes,
                    'code' => $code,
                    'slug' => Str::slug($code.' '.($attributes['title'] ?? 'project')),
                    'status' => ProjectStatus::Draft,
                    'currency' => $attributes['currency'] ?? 'INR',
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }
}
