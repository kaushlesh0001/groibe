<?php

namespace App\Livewire\Settings;

use App\Actions\Privacy\OpenDataRequest;
use App\Enums\DataRequestType;
use App\Exceptions\ActionBlocked;
use App\Models\AgreementAcceptance;
use App\Models\DataRequest;
use App\Support\Throttle;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Where a contributor or client asks for a copy of their data, or for its erasure (spec 17.2, SCR-028), and sees what became of
 * each request. A person sees only their own requests and agreements. An admin has no such page: staff are not data subjects here.
 */
#[Title('Privacy and your data')]
class Privacy extends Component
{
    public ?string $sent = null;

    public ?string $problem = null;

    public function mount(): void
    {
        Gate::authorize('create', DataRequest::class);
    }

    public function requestExport(OpenDataRequest $open): void
    {
        $this->submit($open, DataRequestType::Export, 'Your request for a copy of your data was sent. A Groibe admin will answer it.');
    }

    public function requestDeletion(OpenDataRequest $open): void
    {
        $this->submit($open, DataRequestType::Deletion, 'Your request to erase your data was sent. A Groibe admin will answer it. Nothing changes until they do.');
    }

    private function submit(OpenDataRequest $open, DataRequestType $type, string $message): void
    {
        Gate::authorize('create', DataRequest::class);

        // Three of each kind an hour is more than anyone needs; this stops a script filling the admin queue.
        Throttle::guard('privacy-request', (string) Auth::id(), 6, 3600, 'request');

        try {
            $open(Auth::user(), $type);
            $this->sent = $message;
            $this->problem = null;
        } catch (ActionBlocked $e) {
            $this->problem = $e->getMessage();
            $this->sent = null;
        }
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.settings.privacy', [
            'requests' => DataRequest::query()->where('user_id', $user->getKey())->latest('id')->limit(10)->get(),
            'agreements' => AgreementAcceptance::query()->with('template:id,type,title,version')
                ->where('user_id', $user->getKey())->orderByDesc('accepted_at')->limit(20)->get(),
        ]);
    }
}
