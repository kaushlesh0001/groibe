<?php

use App\Enums\OnboardingState;
use App\Livewire\Contributor\EditProfile;
use App\Models\Profile;
use Livewire\Livewire;

it('cannot open a client profile through the contributor admin page', function () {
    $clientProfile = Profile::factory()->create(['user_id' => clientUser()->id, 'onboarding_state' => OnboardingState::PendingReview]);

    $this->actingAs(adminUser())->get('/admin/contributors/'.$clientProfile->id)->assertNotFound();
});

it('refuses clients on the contributor admin page', function () {
    $contributor = contributorIn(OnboardingState::PendingReview);

    $this->actingAs(clientUser())->get('/admin/contributors/'.$contributor->profile->id)->assertForbidden();
});

it('does not let a suspended contributor change their profile', function () {
    $contributor = contributorIn(OnboardingState::Suspended, ['headline' => 'Original', 'bio' => str_repeat('b', 40), 'city' => 'Pune', 'country' => 'India', 'availability' => 'available']);

    try {
        Livewire::actingAs($contributor)->test(EditProfile::class)->set('form.headline', 'Changed')->call('save');
    } catch (Throwable) {
        // A refusal may surface as an exception or as a validation state; the stored value is what matters.
    }

    expect($contributor->profile->refresh()->headline)->toBe('Original');
});

it('generates the slug when the public toggle is switched on through the profile page', function () {
    $contributor = contributorIn(OnboardingState::Active);

    Livewire::actingAs($contributor)->test(EditProfile::class)->set('isPublic', true);

    expect($contributor->profile->refresh()->is_public)->toBeTrue()->and($contributor->profile->slug)->not->toBeNull();
});
