<?php

namespace App\Livewire\Client\Deliverables;

use App\Actions\Milestones\AcceptMilestone;
use App\Actions\Milestones\RequestMilestoneChanges;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Milestone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Deliverables for review (spec 12): milestones the team has shared, with their deliverables. Owners and
 * managers accept or ask for changes; viewers read only. Contributor names, notes, rates and internal
 * reviewer notes never reach this page.
 */
#[Title('Deliverables')]
class DeliverableIndex extends Component
{
    use WithPagination;

    public ?int $changesFor = null;

    public string $changeNote = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Milestone::class);
    }

    public function accept(int $milestoneId): void
    {
        $this->answer($milestoneId, fn (Milestone $milestone) => app(AcceptMilestone::class)($milestone, Auth::user()), 'Thank you. The milestone is accepted.');
    }

    public function startChanges(int $milestoneId): void
    {
        $this->changesFor = $milestoneId;
        $this->changeNote = '';
        $this->resetErrorBag();
    }

    public function cancelChanges(): void
    {
        $this->reset('changesFor', 'changeNote');
        $this->resetErrorBag();
    }

    public function sendChanges(): void
    {
        if ($this->changesFor === null) {
            return;
        }

        $ok = $this->answer($this->changesFor, fn (Milestone $milestone) => app(RequestMilestoneChanges::class)($milestone, Auth::user(), $this->changeNote), 'Thank you. We will work on your changes.');

        if ($ok) {
            $this->reset('changesFor', 'changeNote');
        }
    }

    public function render()
    {
        $organization = Auth::user()->primaryOrganization();

        $milestones = $this->visibleMilestones()
            ->when($organization === null, fn (Builder $query) => $query->whereKey(0))
            ->when($organization !== null, fn (Builder $query) => $query->whereHas('project', fn (Builder $project) => $project->where('organization_id', $organization->getKey())))
            ->with([
                'project:id,uuid,title,code,organization_id',
                'deliverables' => fn ($deliverables) => $deliverables->orderBy('id'),
                'deliverables.submissions:id,status',
                'deliverables.submissions.media',
            ])
            ->orderByRaw("case status when 'shared' then 0 else 1 end")
            ->latest('shared_at')
            ->paginate(10);

        return view('livewire.client.deliverables.deliverable-index', [
            'milestones' => $milestones,
            // One lookup for the whole page. The Actions still authorize each answer through the policy.
            'mayRespond' => $organization !== null && in_array(Auth::user()->organizationRole($organization), [OrgRole::Owner, OrgRole::Manager], true),
        ]);
    }

    /** @return Builder<Milestone> */
    private function visibleMilestones(): Builder
    {
        // Shared and later only, and never on a draft project (spec 6.9, SCR-009).
        return Milestone::query()
            ->clientVisible()
            ->whereHas('project', fn (Builder $project) => $project->where('status', '!=', ProjectStatus::Draft->value));
    }

    private function answer(int $milestoneId, \Closure $action, string $success): bool
    {
        $organization = Auth::user()->primaryOrganization();

        $milestone = $this->visibleMilestones()
            ->whereHas('project', fn (Builder $project) => $project->where('organization_id', $organization?->getKey()))
            ->findOrFail($milestoneId);

        try {
            $action($milestone);
        } catch (ActionBlocked|IllegalTransition $e) {
            $this->addError('response', $e->getMessage());

            return false;
        } catch (AuthorizationException) {
            $this->addError('response', 'Only organization owners and managers can respond, and only while a milestone is waiting for review.');

            return false;
        }

        session()->flash('status', $success);

        return true;
    }
}
