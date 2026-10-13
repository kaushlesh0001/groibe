<?php

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Import;
use App\Models\User;
use App\Services\Imports\CsvReader;
use App\Services\Imports\ImportDefinition;
use App\Services\Imports\ImportErrorReport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Step 2 of an import: which file column feeds which field (spec 15.1). Changing the mapping after a dry run
 * discards that run's result and sends the import back to `uploaded`, so the numbers on screen always match the mapping.
 */
class SaveImportMapping
{
    public function __construct(private readonly ImportErrorReport $report) {}

    /** @param  array<string, string|null>  $mapping  field => file header; an empty value leaves the field unmapped */
    public function __invoke(Import $import, User $actor, array $mapping): Import
    {
        Gate::forUser($actor)->authorize('update', $import);

        if (! in_array($import->status, [ImportStatus::Uploaded, ImportStatus::Validated], true)) {
            throw IllegalTransition::for('import mapping', $import->status->value, 'changed');
        }

        if (! $import->hasStoredFile()) {
            throw ActionBlocked::guardFailed('The uploaded file was removed after 30 days. Upload it again.');
        }

        $definition = ImportDefinition::for($import->type, false);
        $headers = (new CsvReader(Storage::disk(ImportErrorReport::DISK)->path($import->file_path)))->headers();
        $clean = [];

        foreach ($definition->fields() as $field => $settings) {
            $header = filled($mapping[$field] ?? null) ? (string) $mapping[$field] : null;

            if ($header !== null && ! in_array($header, $headers, true)) {
                throw ActionBlocked::guardFailed("\"{$header}\" is not a column in the file.");
            }

            if ($header === null && $settings['required']) {
                throw ActionBlocked::guardFailed("{$settings['label']} must be matched to a column.");
            }

            $clean[$field] = $header;
        }

        $used = array_filter($clean);

        if (count($used) !== count(array_unique($used))) {
            throw ActionBlocked::guardFailed('A column can feed only one field.');
        }

        $import->mapping = $clean;

        if ($import->status === ImportStatus::Validated) {
            $this->report->reset($import);
            $import->fill(['success_rows' => 0, 'failed_rows' => 0, 'skipped_rows' => 0]);
            $import->moveTo(ImportStatus::Uploaded);
        } else {
            $import->save();
        }

        activity('import')->causedBy($actor)->performedOn($import)->event('mapping_saved')->log('Import columns matched');

        return $import;
    }
}
