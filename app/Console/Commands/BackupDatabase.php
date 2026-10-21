<?php

namespace App\Console\Commands;

use App\Services\Ops\DatabaseDumper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Weekly database dump (spec 17.4), a gzipped SQL file written outside the web root. It is on top of the host's own daily
 * backups, and it is the file the restore drill in the deploy guide uses. The folder is `GROIBE_BACKUP_PATH`, or
 * `storage/backups`; a folder under `public` is refused, because a dump holds personal data. Only the newest few are kept.
 *
 * `--predeploy` (SCR-037) is what `scripts/deploy.sh` runs before a release: the dump is named `groibe-predeploy-DATE-TIME`,
 * is kept under its own retention (`GROIBE_PREDEPLOY_BACKUP_KEEP`, 5) so it never pushes out the weekly dumps, and the last
 * line printed is `BACKUP_FILE=<path>` for the script to check. A database with no tables yet (a first deploy) has nothing
 * to dump: the command writes nothing and exits with code 2, which the script reads as "skip".
 */
class BackupDatabase extends Command
{
    protected $signature = 'groibe:backup-db {--predeploy : Write the dump the deploy script takes before a release}';

    protected $description = 'Write a gzipped SQL dump of the database to a folder outside the web root';

    private const PATTERN = 'groibe-db-*.sql.gz';

    private const PREDEPLOY_PATTERN = 'groibe-predeploy-*.sql.gz';

    /** Exit code for "no tables yet, nothing to back up" (only with --predeploy). */
    public const NO_DATABASE = 2;

    public function handle(DatabaseDumper $dumper): int
    {
        $predeploy = (bool) $this->option('predeploy');

        if ($predeploy && ! Schema::hasTable('migrations')) {
            $this->info('The database has no tables yet, so there is nothing to back up. Skipped.');

            return self::NO_DATABASE;
        }

        $directory = $this->directory();

        if ($this->isUnderPublic($directory)) {
            $this->error('The backup folder is inside the public web folder. Set GROIBE_BACKUP_PATH to a folder outside it.');

            return self::FAILURE;
        }

        if (! File::isDirectory($directory) && ! File::makeDirectory($directory, 0700, true, true)) {
            $this->error('The backup folder could not be created.');

            return self::FAILURE;
        }

        // Created (or followed through a symlink) now, so check where it really is before anything is written.
        if ($this->isUnderPublic((string) realpath($directory))) {
            $this->error('The backup folder resolves to a place inside the public web folder. Choose another one.');

            return self::FAILURE;
        }

        // Belt and braces for a host whose web server is pointed at the wrong folder.
        File::put($directory.DIRECTORY_SEPARATOR.'.htaccess', "Require all denied\n");

        $name = ($predeploy ? 'groibe-predeploy-' : 'groibe-db-').now()->format('Ymd-His').'.sql.gz';
        $final = $directory.DIRECTORY_SEPARATOR.$name;
        $partial = $final.'.part';

        try {
            $stats = $dumper->dump($partial);
            File::move($partial, $final);
            @chmod($final, 0600);
        } catch (Throwable $e) {
            File::delete($partial);
            $this->error('The backup failed: '.$e->getMessage());

            report($e);

            return self::FAILURE;
        }

        $removed = $this->prune($directory, $final, $predeploy);
        $bytes = (int) filesize($final);

        activity('ops')->event('db_backup')
            ->withProperties(['file' => $name, 'predeploy' => $predeploy, 'bytes' => $bytes, 'tables' => $stats['tables'], 'rows' => $stats['rows'], 'old_removed' => $removed])
            ->log('Database backup written');

        $this->info("Wrote {$name} ({$stats['tables']} tables, {$stats['rows']} rows, ".number_format($bytes / 1024, 0).' KB). Removed '.$removed.' old '.($removed === 1 ? 'backup' : 'backups').'.');

        if ($predeploy) {
            // The deploy script reads this line and checks the file itself; it does not trust the exit code alone.
            $this->line('BACKUP_FILE='.$final);
        }

        return self::SUCCESS;
    }

    private function directory(): string
    {
        $configured = config('groibe.ops.backup.path');

        return rtrim(is_string($configured) && trim($configured) !== '' ? trim($configured) : storage_path('backups'), '/\\');
    }

    /** True when the folder is the public folder or lies below it. Works for a folder that does not exist yet. */
    private function isUnderPublic(string $directory): bool
    {
        $public = $this->normalise((string) (realpath(public_path()) ?: public_path()));

        return $this->normalise($directory) === $public || str_starts_with($this->normalise($directory), $public.'/');
    }

    private function normalise(string $path): string
    {
        return rtrim(strtolower(str_replace('\\', '/', $path)), '/');
    }

    /**
     * Deletes all but the newest `keep` dumps of the same kind (always at least the one just written). Weekly and pre-deploy dumps
     * are pruned apart, so neither kind evicts the other. Returns how many were removed.
     */
    private function prune(string $directory, string $justWritten, bool $predeploy): int
    {
        $files = collect(File::glob($directory.DIRECTORY_SEPARATOR.($predeploy ? self::PREDEPLOY_PATTERN : self::PATTERN)) ?: [])
            ->sortDesc()
            ->values();

        $keep = max(1, (int) config($predeploy ? 'groibe.ops.backup.predeploy_keep' : 'groibe.ops.backup.keep'));
        $old = $files->slice($keep)->reject(fn (string $file): bool => $file === $justWritten);

        $old->each(fn (string $file) => File::delete($file));

        return $old->count();
    }
}
