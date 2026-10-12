<?php

namespace App\Services\Imports;

use App\Models\Import;
use App\Services\Finance\CsvExport;
use Illuminate\Support\Facades\Storage;

/**
 * The downloadable report of one import (spec 15.1): a CSV with a line for every row that was refused or skipped, and for
 * every note such as a skill created inactive. It repeats the file's own columns so an admin can correct the rows and
 * upload them again. It holds personal data, so it lives on the private disk and is served only through an authorized action.
 */
class ImportErrorReport
{
    public const DISK = 'local';

    public function __construct(private readonly CsvExport $csv) {}

    public function path(Import $import): string
    {
        return $import->error_report_path ?? "imports/{$import->getKey()}-report.csv";
    }

    /** Removes any earlier report, so a new dry run or run starts from an empty one. */
    public function reset(Import $import): void
    {
        if ($import->error_report_path !== null) {
            Storage::disk(self::DISK)->delete($import->error_report_path);
        }

        $import->error_report_path = null;
    }

    /**
     * @param  'error'|'skipped'|'note'  $severity
     * @param  array<string, string>  $cells  the row as it was in the file, keyed by the file's headers
     */
    public function append(Import $import, int $line, string $severity, string $message, array $cells): void
    {
        $disk = Storage::disk(self::DISK);
        $path = $this->path($import);
        $isNew = ! $disk->exists($path);

        if ($isNew) {
            $disk->put($path, '');
            $import->error_report_path = $path;
        }

        $handle = fopen($disk->path($path), 'ab');

        if ($isNew) {
            fwrite($handle, "\xEF\xBB\xBF");
            $this->write($handle, ['row', 'severity', 'message', ...array_keys($cells)]);
        }

        $this->write($handle, [$line, $severity, $message, ...array_values($cells)]);
        fclose($handle);
    }

    /**
     * @param  resource  $handle
     * @param  list<scalar|null>  $values
     */
    private function write($handle, array $values): void
    {
        fputcsv($handle, array_map($this->csv->safe(...), $values), ',', '"', '');
    }
}
