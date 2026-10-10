<?php

use App\Actions\Contributors\AcceptMemberAgreement;
use App\Actions\Contributors\ApproveContributor;
use App\Actions\Contributors\SubmitContributorForReview;
use App\Actions\Onboarding\RegisterAccount;
use App\Enums\AdminRole;
use App\Enums\OnboardingState;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Contributor\EditProfile;
use App\Livewire\Contributor\Onboarding;
use App\Models\AgreementAcceptance;
use App\Models\Skill;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    publishAgreements();
    Notification::fake();
    $this->skill = Skill::factory()->create(['name' => 'Market sizing']);
});

function registeredContributor()
{
    $user = app(RegisterAccount::class)(UserRole::Contributor, 'Meera Iyer', 'meera@example.test', 'a-long-password-1');
    $user->forceFill(['email_verified_at' => now()])->save();

    return $user->refresh();
}

it('walks a contributor from registration to review, persisting each step', function () {
    $user = registeredContributor();

    $component = Livewire::actingAs($user)->test(Onboarding::class)
        ->assertSet('step', 1)
        ->call('next')
        ->assertSet('step', 2)
        ->set('form.headline', 'Market research analyst')
        ->set('form.bio', 'I run consumer research projects for mid-market brands across India.')
        ->set('form.city', 'Kolkata')
        ->set('form.country', 'India')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', 3);

    expect($user->profile->refresh()->headline)->toBe('Market research analyst');

    $component
        ->set('form.availability', 'available')
        ->set('form.hours_per_week', 12)
        ->set('form.work_modes', ['remote'])
        ->set("form.skillLevels.{$this->skill->id}", 'advanced')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', 4);

    expect($user->profile->refresh()->onboarding_state)->toBe(OnboardingState::ProfileComplete)
        ->and($user->skills()->first()->pivot->level->value)->toBe('advanced');

    // Progress survives leaving and coming back.
    Livewire::actingAs($user)->test(Onboarding::class)->assertSet('step', 4)->assertSet('form.headline', 'Market research analyst');

    $component
        ->set('agreed', true)
        ->call('acceptAgreement')
        ->assertHasNoErrors()
        ->assertSet('step', 5)
        ->call('submitForReview')
        ->assertHasNoErrors();

    expect($user->profile->refresh()->onboarding_state)->toBe(OnboardingState::PendingReview)
        ->and(AgreementAcceptance::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('requires the profile basics and at least one skill before moving on', function () {
    $user = registeredContributor();

    Livewire::actingAs($user)->test(Onboarding::class)
        ->set('step', 2)
        ->call('next')
        ->assertHasErrors(['form.headline', 'form.bio', 'form.city'])
        ->assertSet('step', 2);

    Livewire::actingAs($user)->test(Onboarding::class)
        ->set('step', 3)
        ->set('form.availability', 'available')
        ->call('next')
        ->assertHasErrors('form.skillLevels')
        ->assertSet('step', 3);

    expect($user->profile->refresh()->onboarding_state)->toBe(OnboardingState::Registered);
});

it('rejects an invalid skill level and an unknown availability', function () {
    $user = registeredContributor();

    Livewire::actingAs($user)->test(Onboarding::class)
        ->set('step', 3)
        ->set('form.availability', 'whenever')
        ->set("form.skillLevels.{$this->skill->id}", 'wizard')
        ->call('next')
        ->assertHasErrors(['form.availability', 'form.skillLevels.'.$this->skill->id]);
});

it('requires the agreement box to be ticked', function () {
    $user = completedContributor();

    Livewire::actingAs($user)->test(Onboarding::class)
        ->assertSet('step', 4)
        ->call('acceptAgreement')
        ->assertHasErrors('agreed');

    expect(AgreementAcceptance::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('shows a clear error and stays in profile_complete when submitting without the agreement', function () {
    $user = completedContributor();

    Livewire::actingAs($user)->test(Onboarding::class)
        ->set('step', 5)
        ->call('submitForReview')
        ->assertHasErrors('submit');

    expect($user->profile->refresh()->onboarding_state)->toBe(OnboardingState::ProfileComplete);
});

it('shows the review status read-only once submitted and refuses edits through next()', function () {
    $user = contributorIn(OnboardingState::PendingReview, ['headline' => 'Locked headline']);

    Livewire::actingAs($user)->test(Onboarding::class)
        ->assertSet('step', 5)
        ->assertSee('with our team for review')
        ->set('step', 2)
        ->set('form.headline', 'Changed after submit')
        ->call('next');

    expect($user->profile->refresh()->headline)->toBe('Locked headline');
});

it('lets an admin approve the walked-through contributor and shows them as active', function () {
    $user = completedContributor();
    app(AcceptMemberAgreement::class)($user);
    app(SubmitContributorForReview::class)($user->profile, $user);

    app(ApproveContributor::class)($user->profile->refresh(), adminUser(AdminRole::CommunityManager));

    expect($user->refresh()->status)->toBe(UserStatus::Active);

    $this->actingAs($user)->get('/app/contributor')->assertOk()->assertSee('You are an approved contributor');
});

it('saves the profile page and keeps verification when the level does not change', function () {
    $user = contributorIn(OnboardingState::Active, ['headline' => 'Old', 'bio' => str_repeat('b', 40), 'city' => 'Pune', 'country' => 'India']);
    $user->skills()->attach($this->skill->id, ['level' => 'expert', 'is_verified' => true, 'verified_by' => adminUser()->id, 'verified_at' => now()]);

    Livewire::actingAs($user)->test(EditProfile::class)
        ->set('form.headline', 'New headline')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->profile->refresh()->headline)->toBe('New headline')
        ->and((bool) $user->skills()->first()->pivot->is_verified)->toBeTrue();

    Livewire::actingAs($user)->test(EditProfile::class)
        ->set("form.skillLevels.{$this->skill->id}", 'beginner')
        ->call('save')
        ->assertHasNoErrors();

    expect((bool) $user->skills()->first()->pivot->is_verified)->toBeFalse();
});

it('ignores an inactive skill id posted in the form', function () {
    $inactive = Skill::factory()->create(['is_active' => false]);
    $user = registeredContributor();
    $user->profile->update(['headline' => 'Analyst', 'bio' => str_repeat('b', 40), 'city' => 'Pune', 'country' => 'India']);

    Livewire::actingAs($user)->test(Onboarding::class)
        ->set('step', 3)
        ->set('form.availability', 'available')
        ->set("form.skillLevels.{$this->skill->id}", 'advanced')
        ->set("form.skillLevels.{$inactive->id}", 'advanced')
        ->call('next')
        ->assertHasNoErrors();

    expect($user->skills()->pluck('skills.id')->all())->toBe([$this->skill->id]);
});
