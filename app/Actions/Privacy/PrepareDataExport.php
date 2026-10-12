<?php

namespace App\Actions\Privacy;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\DataRequest;
use App\Models\User;
use App\Services\Privacy\UserDataExporter;
use Illuminate\Support\Facades\Gate;

/**
 * Builds the copy of a person's data for an export request that is in progress (spec 17.2, SCR-028). The copy is made on the
 * spot and never stored, so there is no file at rest to protect. Each time one is made, the audit log says who made it and for
 * which request, and never what it held.
 */
class PrepareDataExport
{
    public function __construct(private readonly UserDataExporter $exporter) {}

    /** @return array<string, mixed> */
    public function __invoke(DataRequest $request, User $actor): array
    {
        Gate::forUser($actor)->authorize('update', $request);

        if ($request->type !== DataRequestType::Export) {
            throw ActionBlocked::guardFailed('Only an export request has a copy to download.');
        }

        if ($request->status !== DataRequestStatus::InProgress) {
            throw IllegalTransition::for('data export', $request->status->value, 'downloaded');
        }

        $data = $this->exporter->build($request->person());

        activity('privacy')->causedBy($actor)->performedOn($request)->event('export_prepared')->log('Data export prepared');

        return $data;
    }
}
