<?php

namespace App\Services\Sows;

use App\Models\Sow;
use Barryvdh\DomPDF\Facade\Pdf;

/** Builds the SOW document (pure PHP dompdf, spec 8). The same Blade view drives print and PDF. */
class SowPdf
{
    public function render(Sow $sow): string
    {
        $sow->loadMissing(['project.organization', 'accepter']);

        return Pdf::loadView('sows.document', ['sow' => $sow, 'forPdf' => true])
            ->setPaper('a4')
            ->output();
    }

    public function filename(Sow $sow): string
    {
        $sow->loadMissing('project');

        return $sow->project->code.'-SOW-v'.$sow->version.'.pdf';
    }
}
