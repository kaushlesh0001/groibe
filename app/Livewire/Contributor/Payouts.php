<?php

namespace App\Livewire\Contributor;

use App\Models\Payout;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Payouts (spec 11, SCR-024): a read-only list of the contributor's own payouts that finance has approved, with their later
 * status. Planned payouts, internal notes and who created a payout are never shown. Clients have no such page.
 */
#[Title('Payouts')]
class Payouts extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.contributor.payouts', [
            'payouts' => Payout::query()
                ->visibleToContributor(Auth::user())
                ->with(['project:id,code,title', 'milestone:id,title'])
                ->orderByDesc('approved_at')
                ->orderByDesc('id')
                ->paginate(15),
        ]);
    }
}
