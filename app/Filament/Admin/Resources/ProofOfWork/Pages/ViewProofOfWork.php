<?php

namespace App\Filament\Admin\Resources\ProofOfWork\Pages;

use App\Filament\Admin\Resources\ProofOfWork\Actions\ProofOfWorkActions;
use App\Filament\Admin\Resources\ProofOfWork\ProofOfWorkResource;
use Filament\Resources\Pages\ViewRecord;

class ViewProofOfWork extends ViewRecord
{
    protected static string $resource = ProofOfWorkResource::class;

    protected function getHeaderActions(): array
    {
        return [ProofOfWorkActions::publish(), ProofOfWorkActions::sendBack(), ProofOfWorkActions::hide()];
    }
}
