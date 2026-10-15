<?php

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Exceptions\ActionBlocked;
use App\Models\Import;
use App\Models\User;
use App\Services\Imports\CsvReader;
use App\Services\Imports\ImportDefinition;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Step 1 of an import (spec 15.1, SCR-025): checks an uploaded CSV, moves it to a random name on the private disk, and
 * records it as `uploaded` with a guessed column mapping. Only CSV is accepted; a spreadsheet is refused with a message.
 */
class StoreImportUpload
{
    private const MAX_COLUMNS = 60;

    /**
     * @param  string  $storedPath  where the upload landed on the private disk (a temporary path, removed here)
     *
     * @throws ActionBlocked when the file is not a usable CSV
     */
    public function __invoke(User $actor, ImportType $type, string $storedPath, string $originalName): Import
    {
        $disk = Storage::disk('local');

        try {
            Gate::forUser($actor)->authorize('create', [Import::class, $type]);

            $headers = $this->check($storedPath, $originalName);

            $path = 'imports/'.Str::lower((string) Str::ulid()).'.csv';
            $disk->move($storedPath, $path);
        } catch (\Throwable $e) {
            $disk->delete($storedPath);

            throw $e;
        }

        $reader = new CsvReader($disk->path($path));

        $import = Import::query()->create([
            'type' => $type,
            'original_name' => Str::limit(basename(str_replace('\\', '/', $originalName)), 200, ''),
            'file_path' => $path,
            'status' => ImportStatus::Uploaded,
            'mapping' => ImportDefinition::for($type, false)->suggestMapping($headers),
            'dry_run' => true,
            'total_rows' => $reader->countRows(),
            'created_by' => $actor->getKey(),
        ]);

        activity('import')->causedBy($actor)->performedOn($import)->event('uploaded')
            ->withProperties(['type' => $type->value, 'rows' => $import->total_rows])->log('Import file uploaded');

        return $import;
    }

    /** @return list<string> the file's headers */
    private function check(string $storedPath, string $originalName): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($storedPath)) {
            throw ActionBlocked::guardFailed('The file could not be read. Upload it again.');
        }

        if (! in_array(Str::lower(pathinfo($originalName, PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
            throw ActionBlocked::guardFailed('Only CSV files can be imported. In Excel, use Save As and choose CSV UTF-8.');
        }

        $limit = (int) config('groibe.imports.max_file_kb') * 1024;

        if ($disk->size($storedPath) > $limit) {
            throw ActionBlocked::guardFailed('The file is larger than '.number_format($limit / 1048576, 0).' MB. Split it into smaller files.');
        }

        $head = (string) file_get_contents($disk->path($storedPath), false, null, 0, 4096);

        if (str_contains($head, "\0") || str_starts_with($head, 'PK')) {
            throw ActionBlocked::guardFailed('That is not a CSV file. In Excel, use Save As and choose CSV UTF-8.');
        }

        try {
            $reader = new CsvReader($disk->path($storedPath));
            $headers = $reader->headers();
            $hasRows = $reader->rows(0, 1)->valid();
        } catch (RuntimeException) {
            throw ActionBlocked::guardFailed('The file could not be read. Upload it again.');
        }

        if ($headers === [] || in_array('', $headers, true)) {
            throw ActionBlocked::guardFailed('The first row must name every column. Remove empty columns and try again.');
        }

        if (count($headers) > self::MAX_COLUMNS) {
            throw ActionBlocked::guardFailed('The file has more than '.self::MAX_COLUMNS.' columns.');
        }

        if (count(array_unique(array_map(Str::lower(...), $headers))) !== count($headers)) {
            throw ActionBlocked::guardFailed('Two columns have the same name. Rename one and try again.');
        }

        if (! $hasRows) {
            throw ActionBlocked::guardFailed('The file has a header row but no data rows.');
        }

        // The check runs in the admin's web request, and a shared host stops one after 30 to 60 seconds. A file this long is split.
        $limit = (int) config('groibe.imports.max_rows');

        if ($reader->countRows() > $limit) {
            throw ActionBlocked::guardFailed('The file has more than '.number_format($limit).' rows. Split it into files of at most '.number_format($limit).' rows and import them one after another.');
        }

        return $headers;
    }
}
