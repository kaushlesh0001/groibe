<?php

namespace App\Livewire\Contributor\Portfolio;

use App\Actions\Reputation\SetProofVisibility;
use App\Enums\ProofVisibility;
use App\Models\ProofOfWork;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/** Portfolio (spec 11): my proof-of-work drafts and published items, with the visibility toggle. */
#[Title('Portfolio')]
class Index extends Component
{
    use WithPagination;

    public function setVisibility(int $proofId, string $visibility): void
    {
        $proof = ProofOfWork::query()->ownedBy(Auth::user())->findOrFail($proofId);
        $value = ProofVisibility::tryFrom($visibility);

        if ($value === null) {
            return;
        }

        try {
            app(SetProofVisibility::class)($proof, Auth::user(), $value);
            session()->flash('status', 'Visibility updated.');
        } catch (AuthorizationException) {
            session()->flash('error', 'That item can no longer be changed.');
        }
    }

    public function render()
    {
        $items = ProofOfWork::query()
            ->ownedBy(Auth::user())
            ->with('project:id,showcase_consent')
            ->latest('proof_of_work.updated_at')
            ->paginate(10);

        return view('livewire.contributor.portfolio.index', ['items' => $items]);
    }
}
