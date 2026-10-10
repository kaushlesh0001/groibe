<?php

namespace App\Livewire\Client\Projects;

use App\Actions\Sows\AcceptSow;
use App\Enums\MilestoneStatus;
use App\Enums\SowStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Sow;
use App\Services\Agreements\AgreementGate;
use App\Services\Projects\ClientTeam;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Read-only project view for the client plus SOW review and acceptance (spec 12). */
#[Title('Project')]
class ProjectShow extends Component
{
    #[Locked]
    public Project $project;

    public bool $confirmed = false;

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);

        $this->project = $project;
    }

    public function accept(int $version): void
    {
        $user = Auth::user();
        $sow = $this->project->sows()->where('version', $version)->firstOrFail();

        try {
            app(AcceptSow::class)($sow, $user, $this->confirmed, request()->ip(), request()->userAgent());
        } catch (ActionBlocked|IllegalTransition|AuthorizationException $e) {
            $this->addError('accept', $e instanceof AuthorizationException ? 'Only the organization owner can accept this Statement of Work.' : $e->getMessage());

            return;
        }

        $this->confirmed = false;
        session()->flash('status', 'Thank you. The Statement of Work has been accepted.');
        $this->redirectRoute('client.projects.show', $this->project, navigate: true);
    }

    public function render()
    {
        $user = Auth::user();
        $project = $this->project->load(['organization', 'projectType:id,name', 'skills:id,name']);

        // Drafts are internal. Superseded versions stay readable as history.
        $sows = $project->sows()->where('status', '!=', SowStatus::Draft->value)->orderByDesc('version')->get();
        $open = $sows->firstWhere('status', SowStatus::Sent);

        $team = app(ClientTeam::class);

        // Totals count every milestone, but only shared and later ones are listed (spec 6.9). Titles of
        // work still in progress stay internal.
        $totals = Milestone::query()
            ->where('project_id', $project->getKey())
            ->toBase()
            ->selectRaw('count(*) as total, coalesce(sum(case when status = ? then 1 else 0 end), 0) as accepted', [MilestoneStatus::ClientAccepted->value])
            ->first();

        return view('livewire.client.projects.project-show', [
            'milestones' => $project->milestones()->clientVisible()->ordered()->get(['id', 'project_id', 'title', 'status', 'due_date', 'shared_at', 'client_accepted_at']),
            'milestoneTotal' => (int) $totals->total,
            'milestonesAccepted' => (int) $totals->accepted,
            'teamHidden' => $team->isHidden($project),
            'teamAvailable' => $team->sowAccepted($project),
            'teamMembers' => $team->members($project),
            'project' => $project,
            'sows' => $sows,
            'openSow' => $open,
            'canAccept' => $open instanceof Sow && $user->can('accept', $open),
            'msaAccepted' => app(AgreementGate::class)->hasAcceptedClientMsa($project->organization),
        ]);
    }
}
