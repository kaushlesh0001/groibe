<?php

namespace App\Livewire\Client\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Projects')]
class ProjectIndex extends Component
{
    use WithPagination;

    public function mount(): void
    {
        Gate::authorize('viewAny', Project::class);
    }

    public function render()
    {
        $organization = Auth::user()->primaryOrganization();

        $projects = Project::query()
            ->when($organization === null, fn ($query) => $query->whereKey(0))
            ->when($organization !== null, fn ($query) => $query->forOrganization($organization))
            // Draft projects are internal until the SOW is sent (see ProjectPolicy::view).
            ->where('status', '!=', ProjectStatus::Draft->value)
            ->with('projectType:id,name')
            ->latest('updated_at')
            ->paginate(10);

        return view('livewire.client.projects.project-index', ['projects' => $projects]);
    }
}
