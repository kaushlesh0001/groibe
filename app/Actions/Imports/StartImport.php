<?php

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Jobs\ProcessImportChunk;
use App\Models\Import;
use App\Models\User;
use App\Services\Imports\ImportErrorReport;
use Illuminate\Support\Facades\Gate;

/** Step 4 of an import (spec 15.1): queues the real run. It needs a dry run first, and something in the file that would be created. */
class StartImport
{
    public function __construct(private readonly ImportErrorReport $report) {}

    public function __invoke(Import $import, User $actor): Import
    {
        Gate::forUser($actor)->authorize('update', $import);

        if ($import->status !== ImportStatus::Validated) {
            throw IllegalTransition::for('import', $import->status->value, ImportStatus::Queued->value);
        }

        if ($import->success_rows < 1) {
            throw ActionBlocked::guardFailed('Nothing in this file would be created, so there is nothing to import.');
        }

        // The run starts its own counts and report; the dry run's are replaced.
        $this->report->reset($import);
        $import->fill(['dry_run' => false, 'success_rows' => 0, 'failed_rows' => 0, 'skipped_rows' => 0]);
        $import->moveTo(ImportStatus::Queued);

        activity('import')->causedBy($actor)->performedOn($import)->event('started')
            ->withProperties(['rows' => $import->total_rows])->log('Import started');

        ProcessImportChunk::dispatch($import->getKey(), 0);

        return $import;
    }
}
