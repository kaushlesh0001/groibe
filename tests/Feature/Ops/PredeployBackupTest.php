<?php

use App\Console\Commands\BackupDatabase;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->backupDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'groibe-predeploy-test-'.uniqid();
    config(['groibe.ops.backup.path' => $this->backupDir, 'groibe.ops.backup.keep' => 2, 'groibe.ops.backup.predeploy_keep' => 5]);
});

afterEach(function () {
    File::deleteDirectory($this->backupDir);
});

function predeployFiles(string $dir): array
{
    return File::glob($dir.DIRECTORY_SEPARATOR.'groibe-predeploy-*.sql.gz') ?: [];
}

function weeklyFiles(string $dir): array
{
    return File::glob($dir.DIRECTORY_SEPARATOR.'groibe-db-*.sql.gz') ?: [];
}

/** Puts dump files of one kind in the folder, oldest first, so the newest names sort last. */
function seedDumps(string $dir, string $prefix, int $count): void
{
    File::ensureDirectoryExists($dir);

    for ($i = 1; $i <= $count; $i++) {
        File::put($dir.DIRECTORY_SEPARATOR.sprintf('%s2025010%d-000000.sql.gz', $prefix, $i), 'old');
    }
}

it('writes a groibe-predeploy dump that restores, and prints the file for the deploy script', function () {
    User::factory()->create(['email' => 'asha@example.test']);

    $this->artisan('groibe:backup-db', ['--predeploy' => true])->assertSuccessful();

    $files = predeployFiles($this->backupDir);
    expect($files)->toHaveCount(1)
        ->and(weeklyFiles($this->backupDir))->toBe([])
        ->and(basename($files[0]))->toMatch('/^groibe-predeploy-\d{8}-\d{6}\.sql\.gz$/');

    Artisan::call('groibe:backup-db', ['--predeploy' => true]);
    expect(Artisan::output())->toMatch('/^BACKUP_FILE=.+groibe-predeploy-\d{8}-\d{6}\.sql\.gz\s*$/m');

    $restored = new PDO('sqlite::memory:');
    $restored->exec((string) gzdecode((string) file_get_contents($files[0])));
    expect((int) $restored->query('select count(*) from users')->fetchColumn())->toBe(User::count());
});

it('keeps the newest 5 pre-deploy dumps', function () {
    seedDumps($this->backupDir, 'groibe-predeploy-', 6);

    $this->artisan('groibe:backup-db', ['--predeploy' => true])->assertSuccessful();

    $names = array_map('basename', predeployFiles($this->backupDir));
    expect($names)->toHaveCount(5)
        ->and($names)->not->toContain('groibe-predeploy-20250101-000000.sql.gz')
        ->and($names)->not->toContain('groibe-predeploy-20250102-000000.sql.gz')
        ->and($names)->toContain('groibe-predeploy-20250106-000000.sql.gz');
});

it('follows GROIBE_PREDEPLOY_BACKUP_KEEP and never keeps fewer than the one just written', function () {
    config(['groibe.ops.backup.predeploy_keep' => 0]);
    seedDumps($this->backupDir, 'groibe-predeploy-', 3);

    $this->artisan('groibe:backup-db', ['--predeploy' => true])->assertSuccessful();

    expect(predeployFiles($this->backupDir))->toHaveCount(1);
});

it('never lets a pre-deploy dump push out the weekly dumps, or the other way round', function () {
    seedDumps($this->backupDir, 'groibe-db-', 2);
    seedDumps($this->backupDir, 'groibe-predeploy-', 5);

    $this->artisan('groibe:backup-db', ['--predeploy' => true])->assertSuccessful();

    expect(weeklyFiles($this->backupDir))->toHaveCount(2)
        ->and(predeployFiles($this->backupDir))->toHaveCount(5);

    $this->artisan('groibe:backup-db')->assertSuccessful();

    expect(weeklyFiles($this->backupDir))->toHaveCount(2)
        ->and(predeployFiles($this->backupDir))->toHaveCount(5);
});

it('writes nothing and exits with code 2 when the database has no tables yet', function () {
    // A database that has never been migrated has no migrations table. (Only that table is dropped: sqlite rolls it back after the test.)
    Schema::dropIfExists('migrations');

    $this->artisan('groibe:backup-db', ['--predeploy' => true])
        ->expectsOutputToContain('no tables yet')
        ->assertExitCode(BackupDatabase::NO_DATABASE);

    expect(is_dir($this->backupDir))->toBeFalse();
});

it('does not take the first-deploy shortcut for the weekly dump', function () {
    $this->artisan('groibe:backup-db')->assertSuccessful();

    expect(weeklyFiles($this->backupDir))->toHaveCount(1);
});

it('refuses a folder inside the public web folder, and fails so the deploy stops', function () {
    config(['groibe.ops.backup.path' => public_path('backups-test-'.uniqid())]);

    $this->artisan('groibe:backup-db', ['--predeploy' => true])->assertFailed();

    expect(File::glob(public_path('backups-test-*')))->toBe([]);
});

it('records the pre-deploy dump in the audit log, with names and counts but no contents', function () {
    User::factory()->create(['email' => 'secret@example.test']);

    $this->artisan('groibe:backup-db', ['--predeploy' => true])->assertSuccessful();

    $entry = Activity::query()->where('event', 'db_backup')->latest('id')->first();

    expect($entry->properties['predeploy'])->toBeTrue()
        ->and($entry->properties['file'])->toStartWith('groibe-predeploy-')
        ->and(json_encode($entry->properties))->not->toContain('secret@example.test');
});

it('reads the keep count from config and the production env template', function () {
    expect(config('groibe.ops.backup.predeploy_keep'))->toBeInt();

    foreach (['.env.production.example', '.env.example'] as $file) {
        expect((string) file_get_contents(base_path($file)))->toContain('GROIBE_PREDEPLOY_BACKUP_KEEP=5');
    }
});
