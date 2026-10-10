<?php

namespace App\Livewire\Client;

use App\Enums\BriefStatus;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Models\Brief;
use App\Models\Project;
use App\Services\Agreements\AgreementGate;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Spec 12 dashboard: what needs the client's attention, and where their work stands. */
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render()
    {
        $organization = Auth::user()->primaryOrganization();

        $awaitingSow = collect();
        $needsInfo = collect();
        $activeProjects = collect();
        $briefCounts = [];

        if ($organization !== null) {
            $awaitingSow = Project::query()->forOrganization($organization)
                ->where('status', ProjectStatus::SowSent->value)->latest('updated_at')->limit(5)->get(['id', 'uuid', 'code', 'title']);

            $needsInfo = Brief::query()->forOrganization($organization)
                ->where('status', BriefStatus::NeedsInfo->value)->latest('updated_at')->limit(5)->get(['id', 'uuid', 'title']);

            $activeProjects = Project::query()->forOrganization($organization)
                ->whereIn('status', [ProjectStatus::SowAccepted->value, ProjectStatus::Staffing->value, ProjectStatus::Active->value, ProjectStatus::InQa->value, ProjectStatus::Delivered->value])
                ->latest('updated_at')->limit(5)->get(['id', 'uuid', 'code', 'title', 'status']);

            $briefCounts = Brief::query()->forOrganization($organization)
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        }

        return view('livewire.client.dashboard', [
            'organization' => $organization,
            'msaPending' => $organization !== null && ! app(AgreementGate::class)->hasAcceptedClientMsa($organization),
            'isOwner' => $organization !== null && Auth::user()->organizationRole($organization) === OrgRole::Owner,
            'awaitingSow' => $awaitingSow,
            'needsInfo' => $needsInfo,
            'activeProjects' => $activeProjects,
            'briefCounts' => $briefCounts,
        ]);
    }
}
