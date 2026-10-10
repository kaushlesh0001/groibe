<?php

namespace App\Filament\Admin\Resources\Briefs\Pages;

use App\Filament\Admin\Resources\Briefs\BriefResource;
use App\Filament\Admin\Resources\Briefs\Pages\Concerns\FillsBriefDisplayData;
use Filament\Resources\Pages\ViewRecord;

/** Read-only brief for admins who may read briefs but not review them (spec 5: community_manager R). */
class ViewBrief extends ViewRecord
{
    use FillsBriefDisplayData;

    protected static string $resource = BriefResource::class;
}
