<?php

namespace App\Livewire\Contributor\Discover;

use App\Actions\Applications\SubmitApplication;
use App\Enums\ApplicationStatus;
use App\Exceptions\ActionBlocked;
use App\Livewire\Forms\ApplicationForm;
use App\Models\Application;
use App\Models\OpenRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One role with its apply form (spec 11). A role the contributor may not see is a 404, whatever the reason. */
#[Title('Role')]
class Show extends Component
{
    #[Locked]
    public int $roleId;

    public ApplicationForm $form;

    public function mount(string $slug): void
    {
        $role = OpenRole::query()->where('slug', $slug)->firstOrFail();

        abort_unless(Gate::forUser(Auth::user())->allows('view', $role), 404);

        $this->roleId = $role->getKey();
    }

    public function apply(): void
    {
        $this->form->validate();

        try {
            app(SubmitApplication::class)($this->role(), Auth::user(), $this->form->payload());
        } catch (ActionBlocked $e) {
            session()->flash('error', $e->getMessage());

            return;
        } catch (AuthorizationException) {
            session()->flash('error', 'You can not apply to this role.');

            return;
        }

        $this->form->reset();
        session()->flash('status', 'Application sent. Groibe will review it and get back to you.');
    }

    private function role(): OpenRole
    {
        return OpenRole::query()->with(['skills', 'projectType'])->findOrFail($this->roleId);
    }

    public function render()
    {
        $user = Auth::user();
        $role = $this->role();
        $application = Application::query()->forUser($user)->where('open_role_id', $role->getKey())->first();

        $canApply = Gate::forUser($user)->allows('apply', $role)
            && ($application === null || $application->status === ApplicationStatus::Withdrawn);

        return view('livewire.contributor.discover.show', [
            'role' => $role,
            'application' => $application,
            'canApply' => $canApply,
        ])->title($role->title);
    }
}
