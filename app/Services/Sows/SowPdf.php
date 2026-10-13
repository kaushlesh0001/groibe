<?php

namespace App\Services\Sows;

use App\Models\Sow;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;

/** Builds the SOW document (pure PHP dompdf, spec 8). The same Blade view drives print and PDF. */
class SowPdf
{
    public function render(Sow $sow): string
    {
        $sow->loadMissing(['project.organization', 'accepter']);

        // dompdf caches the metrics of a font it has not seen before here, and fails without the folder. It is not in Git.
        File::ensureDirectoryExists(storage_path('fonts'));

        return Pdf::loadView('sows.document', ['sow' => $sow, 'forPdf' => true])
            ->setPaper('a4')
            // Embed only the glyphs used, so the two Inter files add a few KB to a SOW instead of 800 KB.
            ->setOption(['enable_font_subsetting' => true])
            ->output();
    }

    public function filename(Sow $sow): string
    {
        $sow->loadMissing('project');

        return $sow->project->code.'-SOW-v'.$sow->version.'.pdf';
    }
}
