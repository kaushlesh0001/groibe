<?php

use App\Enums\AdminRole;
use App\Enums\OnboardingState;
use App\Models\Profile;
use App\Models\User;

it('lets users view and update themselves only', function () {
    $a = contributorUser();
    $b = contributorUser();

    expect($a->can('view', $a))->toBeTrue()
        ->and($a->can('update', $a))->toBeTrue()
        ->and($a->can('view', $b))->toBeFalse()
        ->and($a->can('update', $b))->toBeFalse()
        ->and($a->can('delete', $a))->toBeFalse();
});

it('keeps clients away from contributor and other client records', function () {
    $client = clientUser();

    expect($client->can('view', contributorUser()))->toBeFalse()
        ->and($client->can('view', clientUser()))->toBeFalse()
        ->and($client->can('viewAny', User::class))->toBeFalse();
});

it('applies the admin matrix to contributors', function () {
    $contributor = contributorUser();

    expect(adminUser(AdminRole::DeliveryManager)->can('update', $contributor))->toBeTrue()
        ->and(adminUser(AdminRole::DeliveryManager)->can('delete', $contributor))->toBeFalse()
        ->and(adminUser(AdminRole::CommunityManager)->can('delete', $contributor))->toBeFalse()
        ->and(adminUser(AdminRole::CommunityManager)->can('create', User::class))->toBeTrue()
        ->and(adminUser(AdminRole::FinanceOps)->can('view', $contributor))->toBeTrue()
        ->and(adminUser(AdminRole::FinanceOps)->can('update', $contributor))->toBeFalse()
        ->and(adminUser()->can('delete', $contributor))->toBeTrue();
});

it('keeps admin user records super admin only', function () {
    $target = adminUser(AdminRole::FinanceOps);

    expect(adminUser(AdminRole::DeliveryManager)->can('view', $target))->toBeFalse()
        ->and(adminUser(AdminRole::DeliveryManager)->can('update', $target))->toBeFalse()
        ->and(adminUser()->can('update', $target))->toBeTrue();
});

it('lets only super admin impersonate, and never themselves or another super admin', function () {
    $super = adminUser();
    $target = clientUser();

    expect($super->can('impersonate', $target))->toBeTrue()
        ->and($super->can('impersonate', $super))->toBeFalse()
        ->and($super->can('impersonate', adminUser()))->toBeFalse()
        ->and(adminUser(AdminRole::DeliveryManager)->can('impersonate', $target))->toBeFalse()
        ->and(contributorUser()->can('impersonate', $target))->toBeFalse()
        ->and($super->can('impersonate', User::factory()->client()->suspended()->create()))->toBeFalse();
});

it('exposes public profiles of active contributors to guests only', function () {
    $public = Profile::factory()->public()->inState(OnboardingState::Active)->for(contributorUser())->create();
    $private = Profile::factory()->inState(OnboardingState::Active)->for(contributorUser())->create();
    $suspended = Profile::factory()->public()->inState(OnboardingState::Suspended)->for(User::factory()->contributor()->suspended()->create())->create();
    // Public toggle on but never approved (Pass 3): not visible until the onboarding state is active.
    $unapproved = Profile::factory()->public()->inState(OnboardingState::PendingReview)->for(contributorUser())->create();

    expect(Gate::forUser(null)->allows('view', $public))->toBeTrue()
        ->and(Gate::forUser(null)->allows('view', $private))->toBeFalse()
        ->and(Gate::forUser(null)->allows('view', $suspended))->toBeFalse()
        ->and(Gate::forUser(null)->allows('view', $unapproved))->toBeFalse();
});

it('lets owners and permitted admins edit a profile, nobody else', function () {
    $owner = contributorUser();
    $profile = Profile::factory()->for($owner)->create();

    expect($owner->can('update', $profile))->toBeTrue()
        ->and(contributorUser()->can('update', $profile))->toBeFalse()
        ->and(clientUser()->can('view', $profile))->toBeFalse()
        ->and(adminUser(AdminRole::CommunityManager)->can('update', $profile))->toBeTrue()
        ->and(adminUser(AdminRole::FinanceOps)->can('update', $profile))->toBeFalse();
});
