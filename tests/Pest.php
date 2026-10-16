<?php

use App\Actions\Agreements\AcceptClientMsa;
use App\Actions\Imports\RunImportDryRun;
use App\Actions\Imports\StartImport;
use App\Actions\Imports\StoreImportUpload;
use App\Enums\AdminRole;
use App\Enums\AgreementType;
use App\Enums\AssignmentStatus;
use App\Enums\ImportType;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\UserStatus;
use App\Models\AgreementTemplate;
use App\Models\Assignment;
use App\Models\Import;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use App\Services\Imports\CsvReader;
use Database\Seeders\AgreementTemplateSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => test()->seed(RolesAndPermissionsSeeder::class))
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/** Create an active admin user holding the given permission set. */
function adminUser(AdminRole $role = AdminRole::SuperAdmin): User
{
    return User::factory()->admin($role)->create();
}

function contributorUser(): User
{
    return User::factory()->contributor()->create();
}

/**
 * Publish the seeded agreement templates, as the AgreementTemplateSeeder does, with the placeholder marker taken out of the three
 * documents people accept (Member Agreement, Client MSA, SOW template) so they count as published (spec 8, SCR-036). Terms and
 * Privacy stay placeholders, so the public pages keep answering 404. The marker is removed with a query that skips the model,
 * because a saved version is otherwise immutable.
 */
function publishAgreements(): void
{
    test()->seed(AgreementTemplateSeeder::class);

    AgreementTemplate::query()
        ->whereIn('type', [AgreementType::MemberAgreement, AgreementType::ClientMsa, AgreementType::SowTemplate])
        ->get()
        ->each(fn (AgreementTemplate $template) => DB::table('agreement_templates')->where('id', $template->id)->update([
            'body_html' => str_replace(AgreementTemplate::PLACEHOLDER_NOTE, 'Wording used in tests.', (string) $template->body_html),
        ]));
}

/** Make the organization satisfy the Client MSA gate: its owner accepts the current version. */
function acceptClientMsa(Organization $organization): User
{
    $owner = $organization->users()->wherePivot('org_role', OrgRole::Owner->value)->first()
        ?? clientUser($organization, OrgRole::Owner);

    app(AcceptClientMsa::class)($owner, $organization);

    return $owner;
}

/**
 * Sign in as another user mid-test. The session is cleared first so Filament's AuthenticateSession
 * middleware does not treat the previous user's password hash as a mismatch and log the new user out.
 */
function asUser(User $user): TestCase
{
    test()->flushSession();
    auth()->forgetGuards();

    return test()->actingAs($user);
}

/** Create a client user attached to a (new or given) organization with the given org role. */
function clientUser(?Organization $organization = null, OrgRole $orgRole = OrgRole::Owner): User
{
    $user = User::factory()->client()->create();
    $organization ??= Organization::factory()->verified()->create();
    $organization->users()->attach($user->id, ['org_role' => $orgRole->value]);

    return $user;
}

/**
 * A contributor whose users.status matches the onboarding state, as the transition Actions leave it.
 *
 * @param  array<string, mixed>  $profile
 * @param  array<string, mixed>  $user
 */
function contributorIn(OnboardingState $state, array $profile = [], array $user = []): User
{
    $status = match ($state) {
        OnboardingState::Active => UserStatus::Active,
        OnboardingState::Suspended => UserStatus::Suspended,
        default => UserStatus::Pending,
    };

    $contributor = User::factory()->contributor()->create(['status' => $status, ...$user]);
    Profile::factory()->inState($state)->create(['user_id' => $contributor->id, ...$profile]);

    return $contributor->load('profile');
}

/** A contributor in profile_complete with a skill, ready for the review submission gates. */
function completedContributor(): User
{
    $contributor = contributorIn(OnboardingState::ProfileComplete);
    $contributor->skills()->attach(Skill::factory()->create()->id, ['level' => 'advanced']);

    return $contributor;
}

/**
 * A publicly visible contributor: active, public, with a slug and one skill.
 *
 * @param  array<string, mixed>  $profile
 */
function publicContributor(string $name = 'Asha Rao', array $profile = []): User
{
    $contributor = contributorIn(OnboardingState::Active, ['is_public' => true, 'slug' => str($name)->slug()->toString().'-abc123', ...$profile], ['name' => $name]);
    $contributor->skills()->attach(Skill::factory()->create()->id, ['level' => 'expert']);

    return $contributor;
}

/**
 * A project in the given status for a verified organization with an owner, and one `completed` seat for a public
 * contributor. Returns the pieces the Pass 6 tests need.
 *
 * @param  array<string, mixed>  $project
 * @return array{project: Project, organization: Organization, owner: User, contributor: User, assignment: Assignment}
 */
function projectWithCompletedSeat(ProjectStatus $status = ProjectStatus::Accepted, array $project = []): array
{
    $organization = Organization::factory()->verified()->create(['industry' => 'Logistics']);
    $owner = clientUser($organization, OrgRole::Owner);
    $contributor = publicContributor('Meera Nair', ['slug' => 'meera-nair-'.str()->lower(str()->random(6))]);
    $project = Project::factory()->status($status)->create(['organization_id' => $organization->id, ...$project]);
    $assignment = Assignment::factory()->status(AssignmentStatus::Completed)->create([
        'project_id' => $project->id,
        'user_id' => $contributor->id,
        'role_title' => 'Research Analyst',
    ]);

    return compact('project', 'organization', 'owner', 'contributor', 'assignment');
}

/** CSV text from a header row and data rows, as a spreadsheet would write it. @param list<string> $headers @param list<list<string>> $rows */
function importCsv(array $headers, array $rows, bool $bom = false): string
{
    $out = fopen('php://memory', 'w+');
    fputcsv($out, $headers, ',', '"', '');

    foreach ($rows as $row) {
        fputcsv($out, $row, ',', '"', '');
    }

    rewind($out);
    $csv = (string) stream_get_contents($out);
    fclose($out);

    return ($bom ? "\xEF\xBB\xBF" : '').$csv;
}

/** Upload CSV text as an import, the way the admin screen hands it to the Action. */
function uploadImport(User $actor, ImportType $type, string $csv, string $name = 'people.csv'): Import
{
    Storage::fake('local');
    Storage::disk('local')->put('import-uploads/upload.csv', $csv);

    return app(StoreImportUpload::class)($actor, $type, 'import-uploads/upload.csv', $name);
}

/** Check the file, then run it for real (the test queue runs jobs at once). */
function runImport(Import $import, User $actor): Import
{
    app(RunImportDryRun::class)($import, $actor);
    app(StartImport::class)($import->refresh(), $actor);

    return $import->refresh();
}

/** Rows of an import's report, keyed by header. @return list<array<string, string>> */
function importReport(Import $import): array
{
    $path = Storage::disk('local')->path((string) $import->error_report_path);
    $reader = new CsvReader($path);

    return array_values(iterator_to_array($reader->rows()));
}
