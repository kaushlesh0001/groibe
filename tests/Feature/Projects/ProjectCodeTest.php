<?php

use App\Actions\Projects\CreateProject;
use App\Enums\AdminRole;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Projects\ProjectCodeGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->org = Organization::factory()->verified()->create();
});

function newProject(string $title = 'Dashboard build'): Project
{
    return app(CreateProject::class)(['organization_id' => test()->org->id, 'title' => $title], test()->dm);
}

it('generates sequential GRB-YYYY-NNNN codes', function () {
    $year = now()->year;

    expect(newProject()->code)->toBe("GRB-{$year}-0001")
        ->and(newProject()->code)->toBe("GRB-{$year}-0002")
        ->and(newProject()->code)->toBe("GRB-{$year}-0003");
});

it('restarts the sequence each year', function () {
    expect(app(ProjectCodeGenerator::class)->next(2031))->toBe('GRB-2031-0001');
});

it('counts soft-deleted projects so a code is never reused', function () {
    $first = newProject();
    $first->delete();

    expect(newProject()->code)->toBe('GRB-'.now()->year.'-0002');
});

it('keeps counting correctly past 9999', function () {
    $year = now()->year;
    Project::factory()->create(['code' => "GRB-{$year}-9999"]);

    expect(app(ProjectCodeGenerator::class)->next())->toBe("GRB-{$year}-10000");
});

it('enforces uniqueness in the database', function () {
    $project = newProject();

    expect(fn () => Project::factory()->create(['code' => $project->code]))->toThrow(UniqueConstraintViolationException::class);
});

it('retries when another request wins the same code (race)', function () {
    $year = now()->year;
    $taken = "GRB-{$year}-0001";

    // First candidate collides with a row created by a concurrent request; the second attempt succeeds.
    app()->instance(ProjectCodeGenerator::class, new class($taken, "GRB-{$year}-0002") extends ProjectCodeGenerator
    {
        private int $calls = 0;

        public function __construct(private string $first, private string $second) {}

        public function next(?int $year = null): string
        {
            return $this->calls++ === 0 ? $this->first : $this->second;
        }
    });

    Project::factory()->create(['code' => $taken]);

    expect(newProject()->code)->toBe("GRB-{$year}-0002");
});

it('gives up after repeated collisions instead of looping forever', function () {
    $year = now()->year;
    Project::factory()->create(['code' => "GRB-{$year}-0001"]);

    app()->instance(ProjectCodeGenerator::class, new class extends ProjectCodeGenerator
    {
        public function next(?int $year = null): string
        {
            return 'GRB-'.now()->year.'-0001';
        }
    });

    expect(fn () => newProject())->toThrow(UniqueConstraintViolationException::class);
});

it('creates a draft INR project with a slug built from code and title', function () {
    $project = newProject('Market Study');

    expect($project->status->value)->toBe('draft')
        ->and($project->currency)->toBe('INR')
        ->and($project->slug)->toBe(Str::slug($project->code.' Market Study'))
        ->and($project->uuid)->toHaveLength(26);
});
