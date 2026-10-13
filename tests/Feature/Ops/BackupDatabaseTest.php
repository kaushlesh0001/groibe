<?php

use App\Models\User;
use App\Services\Ops\DatabaseDumper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->backupDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'groibe-backup-test-'.uniqid();
    config(['groibe.ops.backup.path' => $this->backupDir, 'groibe.ops.backup.keep' => 2]);
});

afterEach(function () {
    File::deleteDirectory($this->backupDir);
});

function backupFiles(string $dir): array
{
    return File::glob($dir.DIRECTORY_SEPARATOR.'groibe-db-*.sql.gz') ?: [];
}

it('writes a gzipped dump that restores into an empty database', function () {
    User::factory()->count(3)->create();
    User::factory()->create(['name' => "Asha O'Neil", 'email' => 'asha@example.test']);

    $this->artisan('groibe:backup-db')->assertSuccessful();

    $files = backupFiles($this->backupDir);
    expect($files)->toHaveCount(1);

    $sql = gzdecode((string) file_get_contents($files[0]));
    expect($sql)->toContain('asha@example.test');

    // The proof of a backup is that it restores: load it into a second, empty database and compare.
    $restored = new PDO('sqlite::memory:');
    $restored->exec($sql);

    expect((int) $restored->query('select count(*) from users')->fetchColumn())->toBe(User::count())
        ->and($restored->query("select name from users where email = 'asha@example.test'")->fetchColumn())->toBe("Asha O'Neil")
        ->and((int) $restored->query('select count(*) from roles')->fetchColumn())->toBe(DB::table('roles')->count());
});

it('keeps the structure of sessions and the reset tokens but not their rows', function () {
    DB::table('sessions')->insert(['id' => 'abc123', 'payload' => 'SECRET-SESSION-PAYLOAD', 'last_activity' => time()]);
    DB::table('password_reset_tokens')->insert(['email' => 'reset@example.test', 'token' => 'SECRET-RESET-TOKEN', 'created_at' => now()]);

    $this->artisan('groibe:backup-db')->assertSuccessful();

    $sql = gzdecode((string) file_get_contents(backupFiles($this->backupDir)[0]));

    expect($sql)->toContain('"sessions"')
        ->and($sql)->toContain('"password_reset_tokens"')
        ->and($sql)->not->toContain('SECRET-SESSION-PAYLOAD')
        ->and($sql)->not->toContain('SECRET-RESET-TOKEN');
});

it('refuses a folder inside the public web folder and writes nothing there', function () {
    $inside = public_path('backups-test-'.uniqid());
    config(['groibe.ops.backup.path' => $inside]);

    $this->artisan('groibe:backup-db')
        ->expectsOutputToContain('inside the public web folder')
        ->assertFailed();

    expect(File::exists($inside))->toBeFalse();
});

it('refuses the public folder itself', function () {
    config(['groibe.ops.backup.path' => public_path()]);

    $this->artisan('groibe:backup-db')->assertFailed();

    expect(File::glob(public_path('groibe-db-*')))->toBe([]);
});

it('keeps only the newest dumps', function () {
    File::makeDirectory($this->backupDir, 0700, true);

    foreach (['20200101-000000', '20200102-000000', '20200103-000000'] as $stamp) {
        File::put($this->backupDir.DIRECTORY_SEPARATOR."groibe-db-{$stamp}.sql.gz", gzencode('-- old'));
    }

    $this->artisan('groibe:backup-db')->expectsOutputToContain('Removed 2 old backups')->assertSuccessful();

    $names = array_map('basename', backupFiles($this->backupDir));
    sort($names);

    expect($names)->toHaveCount(2)
        ->and($names[0])->toBe('groibe-db-20200103-000000.sql.gz')
        ->and($names[1])->toStartWith('groibe-db-'.now()->format('Ymd'));
});

it('leaves no partial file and fails clearly when the dump cannot be written', function () {
    File::makeDirectory($this->backupDir, 0700, true);
    $this->app->bind(DatabaseDumper::class, fn () => new class extends DatabaseDumper
    {
        public function dump(string $path): array
        {
            file_put_contents($path, 'half a dump');

            throw new RuntimeException('disk full');
        }
    });

    $this->artisan('groibe:backup-db')->expectsOutputToContain('The backup failed: disk full')->assertFailed();

    expect(File::glob($this->backupDir.DIRECTORY_SEPARATOR.'groibe-db-*'))->toBe([]);
});

it('writes an audit entry with the file name and counts, never the contents', function () {
    User::factory()->create(['email' => 'private@example.test']);

    $this->artisan('groibe:backup-db')->assertSuccessful();

    $entry = Activity::query()->where('log_name', 'ops')->where('event', 'db_backup')->sole();

    expect($entry->properties['file'])->toStartWith('groibe-db-')
        ->and($entry->properties['tables'])->toBeGreaterThan(10)
        ->and($entry->properties['rows'])->toBeGreaterThan(0)
        ->and($entry->properties->toJson())->not->toContain('private@example.test');
});

it('adds a deny-all rule beside the dumps, and the default folder is outside public', function () {
    $this->artisan('groibe:backup-db')->assertSuccessful();

    expect(File::get($this->backupDir.DIRECTORY_SEPARATOR.'.htaccess'))->toContain('Require all denied')
        ->and(str_starts_with(strtolower(str_replace('\\', '/', storage_path('backups'))), strtolower(str_replace('\\', '/', public_path()))))->toBeFalse();
});
