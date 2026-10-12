<?php

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Import;
use App\Models\User;
use App\Services\Imports\ImportDefinition;
use App\Services\Imports\ImportErrorReport;
use App\Services\Imports\ImportRunner;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Step 3 of an import (spec 15.1): reads every row, writes nothing, and records how many rows would be created, skipped or
 * refused, with the report of why. It can be run again after the mapping changes.
 */
class RunImportDryRun
{
    public function __construct(
        private readonly ImportRunner $runner,
        private readonly ImportErrorReport $report,
    ) {}

    public function __invoke(Import $import, User $actor): Import
    {
        Gate::forUser($actor)->authorize('update', $import);

        if (! $import->status->canTransitionTo(ImportStatus::Validated)) {
            throw IllegalTransition::for('import', $import->status->value, ImportStatus::Validated->value);
        }

        $mapping = $import->mapping ?? [];

        foreach (ImportDefinition::for($import->type, false)->fields() as $field => $settings) {
            if ($settings['required'] && blank($mapping[$field] ?? null)) {
                throw ActionBlocked::guardFailed("{$settings['label']} must be matched to a column before the check can run.");
            }
        }

        $this->report->reset($import);

        try {
            $tally = $this->runner->run($import, false, $actor);
        } catch (RuntimeException) {
            throw ActionBlocked::guardFailed('The file could not be read. Upload it again.');
        }

        $import->fill([
            'dry_run' => true,
            'total_rows' => $tally['seen'],
            'success_rows' => $tally['created'],
            'skipped_rows' => $tally['skipped'],
            'failed_rows' => $tally['failed'],
        ]);
        $import->moveTo(ImportStatus::Validated);

        activity('import')->causedBy($actor)->performedOn($import)->event('dry_run')
            ->withProperties(['rows' => $tally['seen'], 'would_create' => $tally['created'], 'skipped' => $tally['skipped'], 'failed' => $tally['failed']])
            ->log('Import checked without saving');

        return $import;
    }
}
