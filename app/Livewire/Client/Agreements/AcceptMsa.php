<?php

namespace App\Livewire\Client\Agreements;

use App\Actions\Agreements\AcceptClientMsa;
use App\Enums\AgreementType;
use App\Enums\OrgRole;
use App\Exceptions\ActionBlocked;
use App\Models\AgreementTemplate;
use App\Services\Agreements\AgreementGate;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The organization owner reads and accepts the current Client MSA (spec 8). */
#[Title('Client MSA')]
class AcceptMsa extends Component
{
    public bool $confirmed = false;

    public function accept(): void
    {
        $user = Auth::user();
        $organization = $user->primaryOrganization();
        abort_if($organization === null, 403);

        $this->validate(['confirmed' => ['accepted']], ['confirmed.accepted' => 'Please tick the box to confirm you accept the agreement.']);

        try {
            app(AcceptClientMsa::class)($user, $organization, request()->ip(), request()->userAgent());
        } catch (ActionBlocked $e) {
            $this->addError('confirmed', $e->getMessage());

            return;
        }

        session()->flash('status', 'Thank you. The Client MSA has been accepted for '.$organization->name.'.');
        $this->redirectRoute('client.briefs.index', navigate: true);
    }

    public function render()
    {
        $user = Auth::user();
        $organization = $user->primaryOrganization();

        return view('livewire.client.agreements.accept-msa', [
            'organization' => $organization,
            'template' => AgreementTemplate::currentPublishedFor(AgreementType::ClientMsa),
            'isOwner' => $organization !== null && $user->organizationRole($organization) === OrgRole::Owner,
            'alreadyAccepted' => $organization !== null && app(AgreementGate::class)->hasAcceptedClientMsa($organization),
        ]);
    }
}
