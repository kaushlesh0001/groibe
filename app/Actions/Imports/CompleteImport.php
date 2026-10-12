<?php

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Models\Import;

/** `running -> completed`, after the last chunk. */
class CompleteImport
{
    public function __invoke(Import $import): Import
    {
        $import->moveTo(ImportStatus::Completed);

        activity('import')->causedBy($import->creator)->performedOn($import)->event('completed')
            ->withProperties(['created' => $import->success_rows, 'skipped' => $import->skipped_rows, 'failed' => $import->failed_rows])
            ->log('Import completed');

        return $import;
    }
}
