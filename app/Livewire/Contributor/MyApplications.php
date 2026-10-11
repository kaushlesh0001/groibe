<?php

namespace App\Livewire\Contributor;

use App\Actions\Applications\AcceptOffer;
use App\Actions\Applications\DeclineOffer;
use App\Actions\Applications\WithdrawApplication;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Application;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/** My Applications (spec 11): status of each application, withdraw, and answering an offer. */
#[Title('My applications')]
class MyApplications extends Component
{
    use WithPagination;

    public function withdraw(int $applicationId): void
    {
        $this->respond($applicationId, WithdrawApplication::class, 'Application withdrawn.');
    }

    public function accept(int $applicationId): void
    {
        $this->respond($applicationId, AcceptOffer::class, 'Offer accepted. Welcome aboard. Check My work for your project.');
    }

    public function decline(int $applicationId): void
    {
        $this->respond($applicationId, DeclineOffer::class, 'Offer declined.');
    }

    public function render()
    {
        $applications = Application::query()
            ->forUser(Auth::user())
            ->with('openRole:id,title,slug,status,project_id')
            ->latest('applications.updated_at')
            ->paginate(15);

        return view('livewire.contributor.my-applications', ['applications' => $applications]);
    }

    /** @param  class-string<WithdrawApplication|AcceptOffer|DeclineOffer>  $action */
    private function respond(int $applicationId, string $action, string $success): void
    {
        $application = Application::query()->forUser(Auth::user())->findOrFail($applicationId);

        try {
            app($action)($application, Auth::user());
            session()->flash('status', $success);
        } catch (ActionBlocked|IllegalTransition $e) {
            session()->flash('error', $e->getMessage());
        } catch (AuthorizationException) {
            session()->flash('error', 'That can no longer be done for this application.');
        }
    }
}
