<?php

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Models\Import;

/** `queued -> running`, when the first chunk starts. Called by the queued job, which has already checked who owns the import. */
class BeginImport
{
    public function __invoke(Import $import): Import
    {
        $import->moveTo(ImportStatus::Running);

        activity('import')->causedBy($import->creator)->performedOn($import)->event('running')->log('Import running');

        return $import;
    }
}
