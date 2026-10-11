<?php

namespace App\Livewire\Contributor\Discover;

use App\Models\OpenRole;
use App\Services\OpenRoles\OpenRoleSearch;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Discover (spec 11): the roles this contributor may see, which is public, members-only and the invite-only roles
 * they are on the list for (SCR-018). Filters can only narrow that set.
 */
#[Title('Discover')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $q = '';

    #[Url]
    public string $skill = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $work_mode = '';

    #[Url]
    public string $compensation = '';

    #[Url]
    public string $duration = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(OpenRoleSearch $search)
    {
        $user = Auth::user();
        // Filters come from the URL, so an invalid one is ignored rather than turned into an error page.
        $filters = OpenRoleSearch::sanitize([
            'q' => $this->q, 'skill' => $this->skill, 'type' => $this->type,
            'work_mode' => $this->work_mode, 'compensation' => $this->compensation, 'duration' => $this->duration,
        ]);

        $roles = $search->apply(OpenRole::query()->visibleTo($user), $filters)
            ->with(['skills', 'projectType'])
            ->orderByDesc('open_roles.published_at')
            ->orderByDesc('open_roles.id')
            ->paginate((int) config('groibe.opportunities.per_page'));

        return view('livewire.contributor.discover.index', [
            'roles' => $roles,
            'options' => $search->options(OpenRole::query()->visibleTo($user)),
        ]);
    }
}
