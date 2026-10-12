<?php

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Models\Import;
use Illuminate\Support\Str;

/** `queued | running | uploaded | validated -> failed`. The reason goes to the audit log, trimmed, and never holds row data. */
class FailImport
{
    public function __invoke(Import $import, string $reason): Import
    {
        $import->moveTo(ImportStatus::Failed);

        activity('import')->causedBy($import->creator)->performedOn($import)->event('failed')
            ->withProperties(['reason' => Str::limit($reason, 300)])->log('Import failed');

        return $import;
    }
}
