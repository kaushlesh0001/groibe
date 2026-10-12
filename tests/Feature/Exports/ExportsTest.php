<?php

use App\Enums\AdminRole;
use App\Enums\ApplicationStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Exceptions\ActionBlocked;
use App\Filament\Admin\Resources\Applications\Pages\ListApplications;
use App\Filament\Admin\Resources\Briefs\Pages\ListBriefs;
use App\Filament\Admin\Resources\Contributors\Pages\ListContributors;
use App\Filament\Admin\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Admin\Resources\Projects\Pages\ListProjects;
use App\Filament\Admin\Resources\Reviews\Pages\ListReviews;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\Application;
use App\Models\Brief;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Review;
use App\Models\Skill;
use App\Models\User;
use App\Services\Exports\CsvExport;
use App\Services\Exports\Definitions\ApplicationExport;
use App\Services\Exports\Definitions\BriefExport;
use App\Services\Exports\Definitions\ClientExport;
use App\Services\Exports\Definitions\ContributorExport;
use App\Services\Exports\Definitions\OrganizationExport;
use App\Services\Exports\Definitions\ProjectExport;
use App\Services\Exports\Definitions\ReviewExport;
use App\Services\Exports\ExportDefinition;
use App\Services\Exports\Exporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The text of a streamed CSV download. */
function exportText(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

/** Rows of a streamed CSV (header first), with the byte order mark removed. @return list<list<string|null>> */
function exportRows(StreamedResponse $response): array
{
    $text = exportText($response);

    return array_map('str_getcsv', array_values(array_filter(explode("\n", str_replace("\r", '', ltrim($text, "\xEF\xBB\xBF"))))));
}

/** Plants text in every free-text and phone field, so a test can prove none of it reaches a file. */
function plantSecrets(string $tag = ''): void
{
    $contributor = User::factory()->contributor()->create(['name' => 'Asha Rao', 'email' => "asha{$tag}@example.com"]);
    Profile::factory()->inState(OnboardingState::Active)->create(['user_id' => $contributor->id, 'phone' => '9800000001', 'bio' => 'SECRET BIO', 'city' => 'Pune', 'experience_years' => 7]);
    $contributor->skills()->attach(Skill::factory()->create(['name' => "Market Research{$tag}"])->id, ['level' => 'advanced']);

    $client = User::factory()->client()->create(['name' => 'Meera Sen', 'email' => "meera{$tag}@acme.example"]);
    Profile::factory()->create(['user_id' => $client->id, 'phone' => '9830000001', 'bio' => 'SECRET BIO']);
    $organization = Organization::factory()->create(['name' => "Acme Foods{$tag}", 'description' => 'SECRET DESCRIPTION']);
    $organization->users()->attach($client->id, ['org_role' => OrgRole::Owner->value, 'designation' => 'Founder']);

    Brief::factory()->create(['title' => 'Market scan', 'organization_id' => $organization->id, 'problem' => 'SECRET PROBLEM', 'desired_outcome' => 'SECRET OUTCOME', 'internal_notes' => 'SECRET INTERNAL', 'needs_info_note' => 'SECRET NOTE']);
    $project = Project::factory()->create(['title' => 'Pricing study', 'organization_id' => $organization->id, 'summary' => 'SECRET SUMMARY', 'scope_summary' => 'SECRET SCOPE']);
    Application::factory()->create(['user_id' => $contributor->id, 'motivation' => 'SECRET MOTIVATION', 'relevant_experience' => 'SECRET EXPERIENCE', 'availability_note' => 'SECRET AVAILABILITY', 'admin_note' => 'SECRET ADMIN NOTE', 'expected_amount' => 5000]);
    Review::factory()->create(['project_id' => $project->id, 'comment' => 'SECRET COMMENT']);
    Review::factory()->ofClient($organization)->create(['project_id' => $project->id, 'comment' => 'SECRET COMMENT TWO']);
}

dataset('exports', [
    'contributors' => [fn () => new ContributorExport, fn () => Profile::query(), 'import_export.contributors'],
    'organizations' => [fn () => new OrganizationExport, fn () => Organization::query(), 'import_export.projects'],
    'clients' => [fn () => new ClientExport, fn () => User::query(), 'import_export.projects'],
    'briefs' => [fn () => new BriefExport, fn () => Brief::query(), 'import_export.projects'],
    'projects' => [fn () => new ProjectExport, fn () => Project::query(), 'import_export.projects'],
    'applications' => [fn () => new ApplicationExport, fn () => Application::query(), 'import_export.projects'],
    'reviews' => [fn () => new ReviewExport, fn () => Review::query(), 'import_export.projects'],
]);

describe('who may export', function () {
    it('lets exactly the roles that hold the permission export each list', function (Closure $definition, Closure $query, string $permission) {
        $holders = $permission === 'import_export.contributors'
            ? [AdminRole::SuperAdmin, AdminRole::DeliveryManager, AdminRole::CommunityManager]
            : [AdminRole::SuperAdmin, AdminRole::DeliveryManager];

        foreach (AdminRole::cases() as $role) {
            $actor = adminUser($role);
            $call = fn () => app(Exporter::class)->export($definition(), $query(), $actor);

            if (in_array($role, $holders, true)) {
                expect($call())->toBeInstanceOf(StreamedResponse::class);
            } else {
                expect($call)->toThrow(AuthorizationException::class);
            }
        }
    })->with('exports');

    it('uses the permission the spec names for each list', function (Closure $definition, Closure $query, string $permission) {
        expect($definition()->permission())->toBe($permission);
    })->with('exports');

    it('refuses contributors, clients and guests even when the permission is somehow theirs', function (Closure $definition, Closure $query, string $permission) {
        foreach ([contributorUser(), clientUser()] as $actor) {
            $actor->givePermissionTo(Permission::findOrCreate($permission));

            expect(fn () => app(Exporter::class)->export($definition(), $query(), $actor))->toThrow(AuthorizationException::class);
        }
    })->with('exports');

    it('shows the button to the right roles on each list page they can open', function (string $page, string $viewPermission, array $holders) {
        foreach (AdminRole::cases() as $role) {
            $actor = adminUser($role);

            // A role that cannot read the list cannot open the page, so there is no button to look for.
            if (! $actor->can($viewPermission)) {
                continue;
            }

            $component = Livewire::actingAs($actor)->test($page);

            in_array($role, $holders, true) ? $component->assertActionVisible('export') : $component->assertActionHidden('export');
        }
    })->with([
        'contributors' => [ListContributors::class, 'contributors.view', [AdminRole::SuperAdmin, AdminRole::DeliveryManager, AdminRole::CommunityManager]],
        'organizations' => [ListOrganizations::class, 'organizations.view', [AdminRole::SuperAdmin, AdminRole::DeliveryManager]],
        'briefs' => [ListBriefs::class, 'briefs.view', [AdminRole::SuperAdmin, AdminRole::DeliveryManager]],
        'projects' => [ListProjects::class, 'projects.view', [AdminRole::SuperAdmin, AdminRole::DeliveryManager]],
        'applications' => [ListApplications::class, 'applications.view', [AdminRole::SuperAdmin, AdminRole::DeliveryManager]],
        'reviews' => [ListReviews::class, 'reviews.view', [AdminRole::SuperAdmin, AdminRole::DeliveryManager]],
        'users (clients)' => [ListUsers::class, 'clients.view', [AdminRole::SuperAdmin, AdminRole::DeliveryManager]],
    ]);
});

describe('the file', function () {
    it('starts with a byte order mark and the agreed headings', function (Closure $definition, Closure $query) {
        plantSecrets();
        $actor = adminUser();

        $text = exportText(app(Exporter::class)->export($definition(), $query(), $actor));

        expect($text)->toStartWith("\xEF\xBB\xBF")
            ->and(str_getcsv(substr(strtok($text, "\n"), 3)))->toBe($definition()->headings());
    })->with('exports');

    it('holds the rows and nothing else', function (Closure $definition, Closure $query) {
        plantSecrets();

        $rows = exportRows(app(Exporter::class)->export($definition(), $query(), adminUser()));

        expect(count($rows))->toBeGreaterThanOrEqual(2)
            ->and(array_unique(array_map('count', $rows)))->toBe([count($definition()->headings())]);
    })->with('exports');

    it('leaves phone numbers, notes, bios, comments and other free text out of every export', function (Closure $definition, Closure $query) {
        plantSecrets();

        $text = exportText(app(Exporter::class)->export($definition(), $query(), adminUser()));

        expect($text)->not->toContain('SECRET')
            ->and($text)->not->toContain('9800000001')
            ->and($text)->not->toContain('9830000001');
    })->with('exports');

    it('carries name and email on lists of people', function () {
        plantSecrets();
        $actor = adminUser();

        $contributors = exportRows(app(Exporter::class)->export(new ContributorExport, Profile::query(), $actor));
        $clients = exportRows(app(Exporter::class)->export(new ClientExport, User::query(), $actor));
        $applicants = exportRows(app(Exporter::class)->export(new ApplicationExport, Application::query(), $actor));

        $row = fn (array $rows, string $name): array => collect($rows)->first(fn (array $line) => in_array($name, $line, true)) ?? [];

        expect($row($contributors, 'Asha Rao'))->toContain('asha@example.com')->toContain('Market Research')
            ->and($row($clients, 'Meera Sen'))->toContain('meera@acme.example')->toContain('Acme Foods')->toContain('Owner')->toContain('Founder')
            ->and($row($applicants, 'Asha Rao'))->toContain('asha@example.com');
    });

    it('names who a review is about, whether a person or an organization', function () {
        plantSecrets();

        $rows = exportRows(app(Exporter::class)->export(new ReviewExport, Review::query()->orderBy('id'), adminUser()));

        expect(collect($rows)->skip(1)->pluck(3)->sort()->values()->all())->toBe(['Organization', 'User'])
            ->and(collect($rows)->skip(1)->pluck(2)->contains('Acme Foods'))->toBeTrue();
    });

    it('never exports admin users, whatever the users list shows', function () {
        plantSecrets();
        $admin = adminUser();
        User::factory()->admin(AdminRole::FinanceOps)->create(['name' => 'Finance Person', 'email' => 'finance@groibe.example']);

        $text = exportText(app(Exporter::class)->export(new ClientExport, User::query(), $admin));

        expect($text)->toContain('Meera Sen')->not->toContain('Finance Person')->not->toContain($admin->email)->not->toContain('Asha Rao');
    });

    it('protects formula-looking cells', function () {
        $contributor = User::factory()->contributor()->create(['name' => '=HYPERLINK("http://evil.example","x")', 'email' => 'x@example.com']);
        Profile::factory()->inState(OnboardingState::Active)->create(['user_id' => $contributor->id, 'headline' => '+cmd|calc', 'city' => '@SUM(1)']);

        $row = exportRows(app(Exporter::class)->export(new ContributorExport, Profile::query(), adminUser()))[1];

        expect($row[0])->toStartWith("'=")->and($row[2])->toStartWith("'@")->and($row[4])->toStartWith("'+");
    });
});

describe('filters, limits and the audit log', function () {
    it('exports what the tab on screen shows', function () {
        foreach ([OnboardingState::Active, OnboardingState::Active, OnboardingState::PendingReview] as $i => $state) {
            Profile::factory()->inState($state)->create(['user_id' => User::factory()->contributor()->create(['name' => "Person {$i}"])->id]);
        }

        $page = Livewire::actingAs(adminUser())->test(ListContributors::class)->set('activeTab', 'active');
        $rows = exportRows(app(Exporter::class)->export(new ContributorExport, $page->instance()->getFilteredTableQuery(), adminUser()));

        expect($rows)->toHaveCount(3);

        $page->set('activeTab', 'queue');
        $rows = exportRows(app(Exporter::class)->export(new ContributorExport, $page->instance()->getFilteredTableQuery(), adminUser()));

        expect($rows)->toHaveCount(2);
    });

    it('exports what the filters on screen show', function () {
        Application::factory()->create();
        Application::factory()->status(ApplicationStatus::Shortlisted)->count(2)->create();

        $page = Livewire::actingAs(adminUser())->test(ListApplications::class)->filterTable('status', ApplicationStatus::Shortlisted->value);
        $rows = exportRows(app(Exporter::class)->export(new ApplicationExport, $page->instance()->getFilteredTableQuery(), adminUser()));

        expect($rows)->toHaveCount(3);
    });

    it('downloads from the screen', function () {
        plantSecrets();

        Livewire::actingAs(adminUser())->test(ListProjects::class)
            ->callAction('export')
            ->assertFileDownloaded();
    });

    it('refuses an export above 5,000 rows, on screen too, and says how to narrow it', function () {
        app()->instance(CsvExport::class, new class extends CsvExport
        {
            public function download(Builder $query, string $name, array $headings, callable $row, User $actor, string $logName): StreamedResponse
            {
                throw ActionBlocked::guardFailed('That is 5,001 rows. Exports are limited to 5,000. Narrow the filters and try again.');
            }
        });

        Livewire::actingAs(adminUser())->test(ListBriefs::class)
            ->callAction('export')
            ->assertNotified('Export not possible')
            ->assertNoFileDownloaded();

        expect(CsvExport::MAX_ROWS)->toBe(5000);
    });

    it('refuses the real thing above the limit before writing anything', function () {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('count')->andReturn(5001);

        expect(fn () => app(CsvExport::class)->download($query, 'briefs', ['a'], fn () => ['a'], adminUser(), 'brief_export'))
            ->toThrow(ActionBlocked::class, 'Narrow the filters');

        expect(Activity::query()->where('log_name', 'brief_export')->exists())->toBeFalse();
    });

    it('writes every export to the audit log with the row count and never the rows', function (Closure $definition, Closure $query) {
        plantSecrets();
        $actor = adminUser();
        $definition = $definition();

        exportText(app(Exporter::class)->export($definition, $query(), $actor));

        $entry = Activity::query()->where('log_name', $definition->logName())->sole();

        expect($entry->event)->toBe('exported')
            ->and($entry->causer_id)->toBe($actor->id)
            ->and($entry->properties['rows'])->toBeInt()->toBeGreaterThanOrEqual(1)
            ->and(json_encode($entry->properties))->not->toContain('Asha')->not->toContain('SECRET');
    })->with('exports');

    it('writes nothing to the audit log when the export is refused for permission', function () {
        plantSecrets();

        expect(fn () => app(Exporter::class)->export(new BriefExport, Brief::query(), adminUser(AdminRole::CommunityManager)))->toThrow(AuthorizationException::class);

        expect(Activity::query()->where('log_name', 'brief_export')->exists())->toBeFalse();
    });
});

describe('query counts', function () {
    it('makes the same number of queries for more rows', function (Closure $definition, Closure $query) {
        plantSecrets();
        $actor = adminUser();

        $count = function () use ($definition, $query, $actor): int {
            $response = app(Exporter::class)->export($definition(), $query(), $actor);

            DB::flushQueryLog();
            DB::enableQueryLog();
            exportText($response);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $before = $count();

        for ($i = 1; $i <= 3; $i++) {
            plantSecrets("-{$i}");
        }

        expect($count())->toBe($before);
    })->with('exports');
});

it('puts the byte order mark on the finance exports too', function () {
    $project = Project::factory()->create();
    $finance = adminUser(AdminRole::FinanceOps);

    $text = exportText(app(CsvExport::class)->download(Project::query()->whereKey($project->id), 'projects', ['Code'], fn (Project $p) => [$p->code], $finance, 'payout_export'));

    expect($text)->toStartWith("\xEF\xBB\xBF");
});

it('has an export definition for every list in spec 15.2 that is not finance', function () {
    $names = collect([new ContributorExport, new OrganizationExport, new ClientExport, new BriefExport, new ProjectExport, new ApplicationExport, new ReviewExport])
        ->map(fn (ExportDefinition $definition) => $definition->name())->sort()->values()->all();

    expect($names)->toBe(['applications', 'briefs', 'clients', 'contributors', 'organizations', 'projects', 'reviews']);
});
