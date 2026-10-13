<?php

use App\Actions\Imports\RunImportDryRun;
use App\Actions\Imports\SaveImportMapping;
use App\Enums\EmailStatus;
use App\Enums\ImportStatus;
use App\Exceptions\ActionBlocked;
use App\Models\EmailLog;
use App\Models\Import;
use App\Services\Website\HomepageBuilder;
use App\Services\Website\PublicCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Spatie\Activitylog\Models\Activity;

// ---- Public cache refresh -------------------------------------------------------------------------------------------

it('clears the homepage and sitemap caches so they rebuild', function () {
    Cache::put(HomepageBuilder::CACHE_KEY, ['stale'], 600);
    Cache::put(PublicCache::SITEMAP_KEY, '<urlset/>', 600);

    $this->artisan('groibe:refresh-public-caches')->assertSuccessful();

    expect(Cache::has(HomepageBuilder::CACHE_KEY))->toBeFalse()
        ->and(Cache::has(PublicCache::SITEMAP_KEY))->toBeFalse();

    $this->get('/')->assertOk();
    $this->get('/sitemap.xml')->assertOk();
});

// ---- Import file clean-up -------------------------------------------------------------------------------------------

function importWithFiles(int $daysOld, ImportStatus $status = ImportStatus::Completed): Import
{
    $import = Import::factory()->status($status)->create([
        'created_at' => now()->subDays($daysOld),
        'total_rows' => 12, 'success_rows' => 10, 'failed_rows' => 2, 'skipped_rows' => 0,
    ]);
    $import->forceFill(['error_report_path' => "imports/{$import->id}-report.csv"])->save();

    Storage::disk('local')->put($import->file_path, "name,email\nAsha,asha@example.test\n");
    Storage::disk('local')->put($import->error_report_path, "row,severity,message\n2,error,Bad\n");

    return $import;
}

beforeEach(function () {
    Storage::fake('local');
    // Livewire fakes its upload disk the first time it is touched, which wipes the disk, so touch it before any file is made.
    FileUploadConfiguration::storage();
});

it('deletes the file and report of an import 30 days after upload and keeps the row, counts and status', function () {
    $old = importWithFiles(31);
    $path = $old->file_path;
    $report = $old->error_report_path;

    $this->artisan('groibe:clean-import-files')->assertSuccessful();

    $old->refresh();

    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(Storage::disk('local')->exists($report))->toBeFalse()
        ->and($old->error_report_path)->toBeNull()
        ->and($old->status)->toBe(ImportStatus::Completed)
        ->and([$old->total_rows, $old->success_rows, $old->failed_rows])->toBe([12, 10, 2])
        ->and($old->hasStoredFile())->toBeFalse();

    expect(Activity::query()->where('event', 'files_removed')->where('subject_id', $old->id)->count())->toBe(1);
});

it('leaves a recent import alone', function () {
    $recent = importWithFiles(29);

    $this->artisan('groibe:clean-import-files')->assertSuccessful();

    expect(Storage::disk('local')->exists($recent->file_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($recent->error_report_path))->toBeTrue()
        ->and($recent->fresh()->error_report_path)->not->toBeNull();
});

it('does nothing the second time, and does not log a removal that did not happen', function () {
    importWithFiles(45);

    $this->artisan('groibe:clean-import-files')->assertSuccessful();
    $this->artisan('groibe:clean-import-files')->expectsOutputToContain('Removed the files of 0 imports')->assertSuccessful();

    expect(Activity::query()->where('event', 'files_removed')->count())->toBe(1);
});

it('deletes temporary uploads left unused for a day and keeps newer ones', function () {
    $tmp = FileUploadConfiguration::storage();
    $dir = FileUploadConfiguration::directory();

    $tmp->put("{$dir}/old-upload.csv", 'x');
    $tmp->put("{$dir}/new-upload.csv", 'x');
    touch($tmp->path("{$dir}/old-upload.csv"), now()->subHours(25)->getTimestamp());
    touch($tmp->path("{$dir}/new-upload.csv"), now()->subHours(2)->getTimestamp());

    $this->artisan('groibe:clean-import-files')->expectsOutputToContain('1 unused temporary upload')->assertSuccessful();

    expect($tmp->exists("{$dir}/old-upload.csv"))->toBeFalse()
        ->and($tmp->exists("{$dir}/new-upload.csv"))->toBeTrue();
});

it('says so, instead of failing, when someone continues an import whose file was removed', function () {
    $admin = adminUser();
    $import = importWithFiles(40, ImportStatus::Uploaded);
    $this->artisan('groibe:clean-import-files')->assertSuccessful();
    $import->refresh();

    foreach ([
        fn () => app(RunImportDryRun::class)($import, $admin),
        fn () => app(SaveImportMapping::class)($import, $admin, []),
    ] as $attempt) {
        expect($attempt)->toThrow(ActionBlocked::class, 'removed after 30 days');
    }
});

// ---- Email log retention (SCR-032) ----------------------------------------------------------------------------------

function emailLogged(EmailStatus $status, int $daysOld): EmailLog
{
    return EmailLog::query()->forceCreate([
        'template' => 'TestNotification',
        'to' => "person-{$daysOld}@example.test",
        'status' => $status,
        'created_at' => now()->subDays($daysOld),
        'updated_at' => now()->subDays($daysOld),
    ]);
}

it('deletes sent rows after 90 days and failed rows after 180 days, by created_at', function () {
    $keep = [
        emailLogged(EmailStatus::Sent, 89),
        emailLogged(EmailStatus::Failed, 179),
        emailLogged(EmailStatus::Failed, 100),   // failed rows outlive sent rows
        emailLogged(EmailStatus::Sent, 1),
    ];
    $drop = [
        emailLogged(EmailStatus::Sent, 91),
        emailLogged(EmailStatus::Sent, 400),
        emailLogged(EmailStatus::Failed, 181),
    ];

    $this->artisan('groibe:prune-email-log')->expectsOutput('Removed 2 sent and 1 failed email log rows.')->assertSuccessful();

    foreach ($keep as $row) {
        expect(EmailLog::query()->whereKey($row->id)->exists())->toBeTrue();
    }

    foreach ($drop as $row) {
        expect(EmailLog::query()->whereKey($row->id)->exists())->toBeFalse();
    }
});

it('writes the number removed to the audit log on every run, even when it is none', function () {
    emailLogged(EmailStatus::Sent, 120);

    $this->artisan('groibe:prune-email-log')->assertSuccessful();
    $this->artisan('groibe:prune-email-log')->assertSuccessful();

    $entries = Activity::query()->where('log_name', 'email_log')->where('event', 'retention')->orderBy('id')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->properties['sent_removed'])->toBe(1)
        ->and($entries[0]->properties['failed_removed'])->toBe(0)
        ->and($entries[1]->properties['sent_removed'])->toBe(0)
        ->and($entries[0]->properties->toJson())->not->toContain('example.test');
});
