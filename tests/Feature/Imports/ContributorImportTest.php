<?php

use App\Actions\Imports\BeginImport;
use App\Actions\Imports\CompleteImport;
use App\Actions\Imports\FailImport;
use App\Actions\Imports\RunImportDryRun;
use App\Actions\Imports\SaveImportMapping;
use App\Actions\Imports\StartImport;
use App\Enums\AdminRole;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\OnboardingState;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Jobs\ProcessImportChunk;
use App\Models\Skill;
use App\Models\User;
use App\Services\Imports\ImportRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

const CONTRIBUTOR_HEADERS = ['name', 'email', 'phone', 'city', 'state', 'headline', 'bio', 'skills', 'experience_years', 'linkedin_url', 'availability', 'status'];

/** A contributor row with the given overrides. @param array<string, string> $with @return list<string> */
function contributorRow(array $with = []): array
{
    $row = array_merge([
        'name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '9800000001', 'city' => 'Pune', 'state' => 'Maharashtra',
        'headline' => 'Market researcher', 'bio' => 'Ten years in research.', 'skills' => '', 'experience_years' => '10',
        'linkedin_url' => 'https://www.linkedin.com/in/asha', 'availability' => 'available', 'status' => '',
    ], $with);

    return array_map(fn (string $header): string => $row[$header], CONTRIBUTOR_HEADERS);
}

beforeEach(function () {
    $this->admin = adminUser();
});

describe('dry run', function () {
    it('reports what would happen and writes nothing', function () {
        Skill::factory()->create(['name' => 'Market Research']);
        $import = uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [
            contributorRow(['skills' => 'Market Research; Brand New Skill']),
            contributorRow(['name' => 'Bad Email', 'email' => 'not-an-email']),
        ]));

        app(RunImportDryRun::class)($import, $this->admin);
        $import->refresh();

        expect($import->status)->toBe(ImportStatus::Validated)
            ->and($import->dry_run)->toBeTrue()
            ->and([$import->total_rows, $import->success_rows, $import->failed_rows, $import->skipped_rows])->toBe([2, 1, 1, 0])
            ->and(User::query()->where('email', 'asha@example.com')->exists())->toBeFalse()
            ->and(Skill::query()->where('name', 'Brand New Skill')->exists())->toBeFalse();

        $messages = collect(importReport($import))->pluck('message')->implode(' ');
        expect($messages)->toContain('Brand New Skill')->toContain('valid email');
    });

    it('can be run again after the mapping changes, and the old result is discarded', function () {
        $import = uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [contributorRow()]));
        app(RunImportDryRun::class)($import, $this->admin);

        $mapping = $import->refresh()->mapping;
        $mapping['phone'] = null;
        app(SaveImportMapping::class)($import, $this->admin, $mapping);

        $import->refresh();
        expect($import->status)->toBe(ImportStatus::Uploaded)
            ->and($import->success_rows)->toBe(0)
            ->and($import->error_report_path)->toBeNull();

        app(RunImportDryRun::class)($import, $this->admin);
        expect($import->refresh()->status)->toBe(ImportStatus::Validated);
    });

    it('needs the required columns matched', function () {
        $import = uploadImport($this->admin, ImportType::Contributors, importCsv(['Full name', 'Mail'], [['Asha', 'asha@example.com']]));

        expect(fn () => app(RunImportDryRun::class)($import, $this->admin))->toThrow(ActionBlocked::class, 'must be matched');
    });

    it('guesses the mapping from the headers', function () {
        $import = uploadImport($this->admin, ImportType::Contributors, importCsv(['Name', 'E-mail', 'Experience Years'], [['Asha', 'asha@example.com', '3']]));

        expect($import->mapping['name'])->toBe('Name')
            ->and($import->mapping['email'])->toBe('E-mail')
            ->and($import->mapping['experience_years'])->toBe('Experience Years')
            ->and($import->mapping['bio'])->toBeNull();
    });
});

describe('import', function () {
    it('creates pending contributors with no password, a profile and their skills', function () {
        $research = Skill::factory()->create(['name' => 'Market Research']);
        $import = uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [
            contributorRow(['skills' => 'market research; Brand New Skill ; ']),
        ]));

        $import = runImport($import, $this->admin);

        $user = User::query()->where('email', 'asha@example.com')->firstOrFail();
        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($import->dry_run)->toBeFalse()
            ->and([$import->success_rows, $import->failed_rows, $import->skipped_rows])->toBe([1, 0, 0])
            ->and($user->role)->toBe(UserRole::Contributor)
            ->and($user->status)->toBe(UserStatus::Pending)
            ->and($user->password)->toBeNull()
            ->and($user->email_verified_at)->toBeNull()
            ->and($user->hasRole('contributor'))->toBeTrue()
            ->and($user->profile->onboarding_state)->toBe(OnboardingState::Registered)
            ->and($user->profile->city)->toBe('Pune')
            ->and($user->profile->experience_years)->toBe(10)
            ->and($user->profile->is_public)->toBeFalse();

        $created = Skill::query()->where('name', 'Brand New Skill')->firstOrFail();
        expect($created->is_active)->toBeFalse()
            ->and($user->skills()->pluck('skills.id')->all())->toEqualCanonicalizing([$research->id, $created->id])
            ->and(DB::table('skill_user')->where('user_id', $user->id)->where('is_verified', true)->doesntExist())->toBeTrue();

        expect(collect(importReport($import))->pluck('message')->implode(' '))->toContain('"Brand New Skill" was created inactive');
    });

    it('is idempotent by email: a second run of the same file creates nothing', function () {
        $csv = importCsv(CONTRIBUTOR_HEADERS, [contributorRow(), contributorRow(['name' => 'Ravi Nair', 'email' => 'ravi@example.com'])]);

        runImport(uploadImport($this->admin, ImportType::Contributors, $csv), $this->admin);

        // The same file again: the check already says so, and the run is refused as pointless.
        $again = uploadImport($this->admin, ImportType::Contributors, $csv);
        app(RunImportDryRun::class)($again, $this->admin);
        expect([$again->refresh()->success_rows, $again->skipped_rows])->toBe([0, 2])
            ->and(fn () => app(StartImport::class)($again, $this->admin))->toThrow(ActionBlocked::class, 'nothing to import');

        expect(User::query()->where('role', UserRole::Contributor->value)->count())->toBe(2);
    });

    it('creates nothing twice when the same people arrive between the check and the run', function () {
        $csv = importCsv(CONTRIBUTOR_HEADERS, [contributorRow(), contributorRow(['name' => 'Ravi Nair', 'email' => 'ravi@example.com'])]);

        $first = uploadImport($this->admin, ImportType::Contributors, $csv);
        app(RunImportDryRun::class)($first, $this->admin);
        $firstPath = $first->file_path;
        $content = Storage::disk('local')->get($firstPath);

        // Another import creates the same people before the first one is started.
        runImport(uploadImport($this->admin, ImportType::Contributors, $csv), $this->admin);
        Storage::disk('local')->put($firstPath, $content);

        app(StartImport::class)($first->refresh(), $this->admin);

        expect($first->refresh()->status)->toBe(ImportStatus::Completed)
            ->and([$first->success_rows, $first->skipped_rows])->toBe([0, 2])
            ->and(User::query()->where('role', UserRole::Contributor->value)->count())->toBe(2);
    });

    it('does not overwrite a person who already exists', function () {
        $existing = User::factory()->contributor()->create(['name' => 'Edited Name', 'email' => 'asha@example.com']);
        $import = uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [
            contributorRow(),
            contributorRow(['name' => 'Ravi Nair', 'email' => 'ravi@example.com']),
        ]));

        $import = runImport($import, $this->admin);

        expect($existing->refresh()->name)->toBe('Edited Name')
            ->and([$import->success_rows, $import->skipped_rows])->toBe([1, 1]);
    });

    it('skips the second row of a repeated email and matches emails without case', function () {
        $import = runImport(uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [
            contributorRow(),
            contributorRow(['name' => 'Asha Again', 'email' => 'ASHA@Example.com']),
        ])), $this->admin);

        expect([$import->success_rows, $import->skipped_rows])->toBe([1, 1])
            ->and(User::query()->where('email', 'asha@example.com')->count())->toBe(1);
    });

    it('refuses to start a file that would create nothing', function () {
        User::factory()->client()->create(['email' => 'asha@example.com']);
        $import = uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [contributorRow()]));

        app(RunImportDryRun::class)($import, $this->admin);

        expect($import->refresh()->failed_rows)->toBe(1)
            ->and(importReport($import)[0]['message'])->toContain('belongs to a client account')
            ->and(fn () => app(StartImport::class)($import, $this->admin))->toThrow(ActionBlocked::class, 'nothing to import');
    });

    it('refuses a status other than pending, bad numbers, bad links and unknown availability', function () {
        $import = uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [
            contributorRow(['email' => 'a@example.com', 'status' => 'active']),
            contributorRow(['email' => 'b@example.com', 'experience_years' => '90']),
            contributorRow(['email' => 'c@example.com', 'linkedin_url' => 'javascript:alert(1)']),
            contributorRow(['email' => 'd@example.com', 'availability' => 'sometimes']),
            contributorRow(['email' => 'e@example.com', 'name' => '']),
            contributorRow(['email' => 'f@example.com', 'status' => 'Pending']),
        ]));

        app(RunImportDryRun::class)($import, $this->admin);

        expect([$import->refresh()->success_rows, $import->failed_rows])->toBe([1, 5]);
    });

    it('keeps going past refused rows and lists each one in the report with its original columns', function () {
        $import = runImport(uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [
            contributorRow(['email' => 'good@example.com']),
            contributorRow(['email' => 'broken']),
        ])), $this->admin);

        $report = importReport($import);

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and([$import->success_rows, $import->failed_rows])->toBe([1, 1])
            ->and($report)->toHaveCount(1)
            ->and($report[0]['row'])->toBe('3')
            ->and($report[0]['severity'])->toBe('error')
            ->and($report[0]['email'])->toBe('broken');
    });

    it('neutralises spreadsheet formulas in the report', function () {
        $import = runImport(uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [
            contributorRow(['email' => 'good@example.com']),
            contributorRow(['email' => 'bad@example.com', 'name' => '=HYPERLINK("http://evil","x")', 'availability' => 'nope']),
        ])), $this->admin);

        $line = importReport($import)[0];

        expect($line['name'])->toStartWith("'=");
    });

    it('runs in chunks and finishes with the right totals', function () {
        config(['groibe.imports.chunk_size' => 2]);
        $rows = array_map(fn (int $i): array => contributorRow(['name' => "Person {$i}", 'email' => "p{$i}@example.com"]), range(1, 5));
        $rows[] = contributorRow(['name' => 'Dup', 'email' => 'p1@example.com']);
        $rows[] = contributorRow(['name' => 'Broken', 'email' => 'broken']);

        $import = runImport(uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, $rows)), $this->admin);

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and([$import->total_rows, $import->success_rows, $import->skipped_rows, $import->failed_rows])->toBe([7, 5, 1, 1])
            ->and(User::query()->where('role', UserRole::Contributor->value)->count())->toBe(5);
    });

    it('writes one audit entry per created person, stamped with the import', function () {
        $import = runImport(uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [contributorRow()])), $this->admin);

        $entry = Activity::query()->where('log_name', 'import')->where('event', 'imported')->firstOrFail();

        expect($entry->properties['import_id'])->toBe($import->id)
            ->and($entry->causer_id)->toBe($this->admin->id)
            ->and(Activity::query()->where('log_name', 'import')->whereIn('event', ['uploaded', 'dry_run', 'started', 'running', 'completed'])->count())->toBe(5);
    });

    it('reads a file saved by Excel with a byte order mark', function () {
        $import = runImport(uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [contributorRow(['name' => 'Ananya Iyer', 'email' => 'ananya@example.com'])], bom: true)), $this->admin);

        expect($import->success_rows)->toBe(1)
            ->and(User::query()->where('email', 'ananya@example.com')->value('name'))->toBe('Ananya Iyer');
    });

    it('reads an older Excel CSV saved as Windows-1252 and keeps the accents', function () {
        $csv = mb_convert_encoding(importCsv(CONTRIBUTOR_HEADERS, [contributorRow(['name' => 'José Fernández', 'email' => 'jose@example.com'])]), 'Windows-1252', 'UTF-8');

        $import = runImport(uploadImport($this->admin, ImportType::Contributors, $csv), $this->admin);

        expect(User::query()->where('email', 'jose@example.com')->value('name'))->toBe('José Fernández');
    });

    it('imports a file whose columns are in another order and have other names once matched', function () {
        $csv = importCsv(['Email address', 'Full name'], [['sam@example.com', 'Sam Das']]);
        $import = uploadImport($this->admin, ImportType::Contributors, $csv);

        app(SaveImportMapping::class)($import, $this->admin, ['name' => 'Full name', 'email' => 'Email address']);
        $import = runImport($import->refresh(), $this->admin);

        expect(User::query()->where('email', 'sam@example.com')->value('name'))->toBe('Sam Das');
    });
});

describe('state machine', function () {
    it('cannot start before a dry run', function () {
        $import = uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [contributorRow()]));

        expect(fn () => app(StartImport::class)($import, $this->admin))->toThrow(IllegalTransition::class);
    });

    it('cannot change the mapping or check again once queued, running or finished', function (ImportStatus $status) {
        $import = uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [contributorRow()]));
        $import->forceFill(['status' => $status])->save();

        expect(fn () => app(SaveImportMapping::class)($import, $this->admin, $import->mapping))->toThrow(IllegalTransition::class)
            ->and(fn () => app(RunImportDryRun::class)($import, $this->admin))->toThrow(IllegalTransition::class);
    })->with([ImportStatus::Queued, ImportStatus::Running, ImportStatus::Completed, ImportStatus::Failed]);

    it('cannot be started twice', function () {
        $import = runImport(uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [contributorRow()])), $this->admin);

        expect(fn () => app(StartImport::class)($import, $this->admin))->toThrow(IllegalTransition::class);
    });

    it('allows exactly the transitions SCR-027 lists', function () {
        $allowed = [
            'uploaded' => ['validated', 'failed'],
            'validated' => ['validated', 'uploaded', 'queued', 'failed'],
            'queued' => ['running', 'failed'],
            'running' => ['completed', 'failed'],
            'completed' => [],
            'failed' => [],
        ];

        foreach (ImportStatus::cases() as $status) {
            expect(array_map(fn (ImportStatus $to) => $to->value, $status->allowedTransitions()))->toBe($allowed[$status->value]);
        }
    });

    it('fails the import when the person who started it is suspended before the job runs', function () {
        Queue::fake();
        $import = uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [contributorRow()]));
        app(RunImportDryRun::class)($import, $this->admin);
        app(StartImport::class)($import->refresh(), $this->admin);

        $this->admin->forceFill(['status' => UserStatus::Suspended])->save();
        (new ProcessImportChunk($import->id, 0))->handle(app(ImportRunner::class), app(BeginImport::class), app(CompleteImport::class), app(FailImport::class));

        expect($import->refresh()->status)->toBe(ImportStatus::Failed)
            ->and(User::query()->where('email', 'asha@example.com')->exists())->toBeFalse();
    });

    it('stops when the owner loses the permission', function () {
        Queue::fake();
        $manager = adminUser(AdminRole::CommunityManager);
        $import = uploadImport($manager, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [contributorRow()]));
        app(RunImportDryRun::class)($import, $manager);
        app(StartImport::class)($import->refresh(), $manager);

        $manager->syncRoles([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (new ProcessImportChunk($import->id, 0))->handle(app(ImportRunner::class), app(BeginImport::class), app(CompleteImport::class), app(FailImport::class));

        expect($import->refresh()->status)->toBe(ImportStatus::Failed);
    });

    it('fails the import and keeps going state honest when a chunk throws', function () {
        $import = uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [contributorRow()]));
        app(RunImportDryRun::class)($import, $this->admin);
        $import->refresh();
        Storage::disk('local')->delete($import->file_path);

        expect(fn () => app(StartImport::class)($import, $this->admin))->toThrow(RuntimeException::class);
        expect($import->refresh()->status)->toBe(ImportStatus::Failed);
    });
});

it('keeps the upload and report off the public disk', function () {
    $import = runImport(uploadImport($this->admin, ImportType::Contributors, importCsv(CONTRIBUTOR_HEADERS, [contributorRow(), contributorRow(['email' => 'x'])])), $this->admin);

    expect(Storage::disk('local')->exists($import->file_path))->toBeTrue()
        ->and(Storage::disk('local')->exists((string) $import->error_report_path))->toBeTrue()
        ->and(Storage::disk('public')->exists($import->file_path))->toBeFalse()
        ->and($import->file_path)->not->toContain('people');
});
