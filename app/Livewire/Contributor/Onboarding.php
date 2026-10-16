<?php

namespace App\Livewire\Contributor;

use App\Actions\Contributors\AcceptMemberAgreement;
use App\Actions\Contributors\CompleteContributorProfile;
use App\Actions\Contributors\SubmitContributorForReview;
use App\Enums\AgreementType;
use App\Enums\OnboardingState;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Livewire\Contributor\Concerns\ManagesContributorProfile;
use App\Livewire\Forms\ContributorProfileForm;
use App\Models\AgreementTemplate;
use App\Services\Contributors\ProfileCompleteness;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Contributor onboarding (spec 6.10, 11): account, profile, skills and availability, Member Agreement,
 * submit for review. Every step persists on Next, so a contributor can leave and come back.
 */
#[Title('Onboarding')]
class Onboarding extends Component
{
    use ManagesContributorProfile;

    public const STEPS = 5;

    #[Url]
    public int $step = 1;

    public bool $agreed = false;

    public function mount(): void
    {
        $this->loadForm();

        $state = $this->state();

        if ($state === OnboardingState::Registered) {
            $this->step = max(1, min($this->step, 3));
        } elseif ($state === OnboardingState::ProfileComplete) {
            $this->step = in_array($this->step, [4, 5], true) ? $this->step : 4;
        } else {
            $this->step = 5;
        }

        if ($this->step === 1 && $this->profileLooksStarted()) {
            $this->step = 2;
        }
    }

    public function next(): void
    {
        $this->resetErrorBag();

        if ($this->locked()) {
            return;
        }

        match ($this->step) {
            2 => $this->saveProfileSection(),
            3 => $this->finishSkillsStep(),
            default => null,
        };

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $this->step = min($this->step + 1, self::STEPS);
    }

    public function back(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    public function acceptAgreement(): void
    {
        $this->resetErrorBag();
        $this->validate(['agreed' => ['accepted']], ['agreed.accepted' => 'Please tick the box to confirm you accept the agreement.']);

        try {
            app(AcceptMemberAgreement::class)(Auth::user(), request()->ip(), request()->userAgent());
        } catch (ActionBlocked $e) {
            $this->addError('agreed', $e->getMessage());

            return;
        }

        $this->step = 5;
    }

    public function submitForReview(): void
    {
        $this->resetErrorBag();

        try {
            app(SubmitContributorForReview::class)($this->profile(), Auth::user());
        } catch (ActionBlocked|IllegalTransition $e) {
            $this->addError('submit', $e->getMessage());

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->step = 5;
    }

    private function finishSkillsStep(): void
    {
        $this->saveAvailabilityAndSkills();

        if ($this->getErrorBag()->isNotEmpty() || $this->state() !== OnboardingState::Registered) {
            return;
        }

        try {
            app(CompleteContributorProfile::class)($this->profile(), Auth::user());
        } catch (ActionBlocked|IllegalTransition $e) {
            $this->addError('submit', $e->getMessage());
        }
    }

    private function state(): OnboardingState
    {
        return $this->profile()->onboarding_state ?? OnboardingState::Registered;
    }

    /** Past registration but before review: the steps can still be edited. */
    private function locked(): bool
    {
        return ! in_array($this->state(), [OnboardingState::Registered, OnboardingState::ProfileComplete], true);
    }

    private function profileLooksStarted(): bool
    {
        return filled($this->form->headline) || filled($this->form->bio);
    }

    public function render()
    {
        $user = Auth::user();
        $state = $this->state();
        $template = AgreementTemplate::currentPublishedFor(AgreementType::MemberAgreement);

        return view('livewire.contributor.onboarding', [
            'state' => $state,
            'locked' => $this->locked(),
            'emailVerified' => $user->email_verified_at !== null,
            'agreementAccepted' => app(ProfileCompleteness::class)->hasAcceptedMemberAgreement($user),
            'template' => $template,
            'skillGroups' => $this->step === 3 ? $this->skillGroups() : collect(),
            'levels' => $this->levels(),
            'workModes' => ContributorProfileForm::WORK_MODES,
        ]);
    }
}
