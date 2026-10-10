<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Sow;
use App\Services\Sows\SowPdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Print view and PDF of a SOW version for members of the project's organization (spec 8). */
class SowDocumentController extends Controller
{
    public function print(Project $project, int $version): View
    {
        return view('sows.document', ['sow' => $this->sow($project, $version), 'forPdf' => false]);
    }

    public function pdf(Project $project, int $version, SowPdf $pdf): Response
    {
        $sow = $this->sow($project, $version);

        return response($pdf->render($sow), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($sow).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function sow(Project $project, int $version): Sow
    {
        Gate::authorize('view', $project);

        $sow = $project->sows()->where('version', $version)->firstOrFail();
        $sow->setRelation('project', $project->loadMissing('organization'));

        Gate::authorize('view', $sow);

        return $sow;
    }
}
