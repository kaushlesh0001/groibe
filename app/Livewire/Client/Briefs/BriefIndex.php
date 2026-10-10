<?php

namespace App\Livewire\Client\Briefs;

use App\Models\Brief;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Briefs')]
class BriefIndex extends Component
{
    use WithPagination;

    public function mount(): void
    {
        Gate::authorize('viewAny', Brief::class);
    }

    public function render()
    {
        $user = Auth::user();
        $organization = $user->primaryOrganization();

        $briefs = Brief::query()
            ->when($organization === null, fn ($query) => $query->whereKey(0))
            ->when($organization !== null, fn ($query) => $query->forOrganization($organization))
            ->with('projectType:id,name')
            ->latest('updated_at')
            ->paginate(10);

        return view('livewire.client.briefs.brief-index', [
            'briefs' => $briefs,
            'canCreate' => $user->can('create', Brief::class),
        ]);
    }
}
