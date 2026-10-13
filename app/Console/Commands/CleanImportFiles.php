<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Services\Imports\ImportErrorReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/**
 * Daily housekeeping (spec 17.4, SCR-034). An uploaded CSV and its error report hold personal data, so both are deleted 30
 * days after the upload. The import row, its counts and its status stay, and the report path is cleared. Temporary uploads
 * nobody used within a day are deleted too.
 */
class CleanImportFiles extends Command
{
    protected $signature = 'groibe:clean-import-files';

    protected $description = 'Delete import files and reports 30 days after upload, and unused temporary uploads after a day';

    public function handle(): int
    {
        $disk = Storage::disk(ImportErrorReport::DISK);
        $cutoff = now()->subDays(max(1, (int) config('groibe.ops.import_file_days')));
        $imports = 0;

        Import::query()
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($disk, &$imports): void {
                foreach ($chunk as $import) {
                    $had = $disk->exists($import->file_path) || $import->error_report_path !== null;

                    if (! $had) {
                        continue;
                    }

                    $disk->delete($import->file_path);

                    if ($import->error_report_path !== null) {
                        $disk->delete($import->error_report_path);
                        $import->forceFill(['error_report_path' => null])->save();
                    }

                    activity('import')->performedOn($import)->event('files_removed')
                        ->withProperties(['days' => (int) config('groibe.ops.import_file_days')])
                        ->log('Import file and report removed after the retention period');

                    $imports++;
                }
            });

        $temporary = $this->removeStaleTemporaryUploads();

        $this->info("Removed the files of {$imports} ".($imports === 1 ? 'import' : 'imports')." and {$temporary} unused temporary ".($temporary === 1 ? 'upload' : 'uploads').'.');

        return self::SUCCESS;
    }

    /** Deletes files in the Livewire temporary upload folder that were last written more than a day ago. */
    private function removeStaleTemporaryUploads(): int
    {
        $storage = FileUploadConfiguration::storage();
        $limit = now()->subDay()->getTimestamp();
        $removed = 0;

        foreach ($storage->files(FileUploadConfiguration::directory()) as $file) {
            if ($storage->lastModified($file) < $limit) {
                $storage->delete($file);
                $removed++;
            }
        }

        return $removed;
    }
}
