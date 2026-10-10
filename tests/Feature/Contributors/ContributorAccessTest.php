<?php

use App\Enums\AdminRole;
use App\Enums\OnboardingState;
use App\Enums\UserStatus;
use App\Models\Profile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

it('gives review abilities to community managers and super admins only', function (AdminRole $role, bool $allowed) {
    $admin = adminUser($role);
    $profile = contributorIn(OnboardingState::PendingReview)->profile;

    foreach (['approve', 'reject', 'suspend', 'reinstate', 'verifySkills'] as $ability) {
        expect(Gate::forUser($admin)->allows($ability, $profile))->toBe($allowed, "{$role->value} {$ability}");
    }
})->with([
    'super_admin' => [AdminRole::SuperAdmin, true],
    'community_manager' => [AdminRole::CommunityManager, true],
    'delivery_manager' => [AdminRole::DeliveryManager, false],
    'finance_ops' => [AdminRole::FinanceOps, false],
]);

it('lets delivery managers read and update but not approve (spec 5)', function () {
    $delivery = adminUser(AdminRole::DeliveryManager);
    $profile = contributorIn(OnboardingState::PendingReview)->profile;

    expect(Gate::forUser($delivery)->allows('view', $profile))->toBeTrue()
        ->and(Gate::forUser($delivery)->allows('update', $profile))->toBeTrue()
        ->and(Gate::forUser($delivery)->allows('approve', $profile))->toBeFalse();
});

it('denies clients and other contributors every review ability and any edit of someone else', function () {
    $profile = contributorIn(OnboardingState::PendingReview)->profile;
    $client = clientUser();
    $otherContributor = contributorIn(OnboardingState::Active);

    foreach ([$client, $otherContributor] as $actor) {
        foreach (['approve', 'reject', 'suspend', 'reinstate', 'verifySkills', 'update', 'completeProfile', 'submitForReview'] as $ability) {
            expect(Gate::forUser($actor)->allows($ability, $profile))->toBeFalse("{$ability}");
        }
    }
});

it('does not let review abilities apply to a client profile', function () {
    $clientProfile = Profile::factory()->create(['user_id' => clientUser()->id]);

    expect(Gate::forUser(adminUser())->allows('approve', $clientProfile))->toBeFalse();
});

it('blocks every non-active contributor state from guarded areas with a status page', function (OnboardingState $state) {
    Route::middleware(['web', 'auth', 'active.contributor'])->get('/__probe/active-only', fn () => 'inside');

    $contributor = contributorIn($state);

    $this->actingAs($contributor)
        ->get('/__probe/active-only')
        ->assertForbidden()
        ->assertSee('This area is not available yet');
})->with([
    OnboardingState::Registered,
    OnboardingState::ProfileComplete,
    OnboardingState::PendingReview,
    OnboardingState::Rejected,
    OnboardingState::Suspended,
]);

it('lets an active contributor into guarded areas', function () {
    Route::middleware(['web', 'auth', 'active.contributor'])->get('/__probe/active-only', fn () => 'inside');

    $this->actingAs(contributorIn(OnboardingState::Active))->get('/__probe/active-only')->assertOk()->assertSee('inside');
});

it('blocks a suspended contributor even if their onboarding state still says active', function () {
    Route::middleware(['web', 'auth', 'active.contributor'])->get('/__probe/active-only', fn () => 'inside');

    $contributor = contributorIn(OnboardingState::Active);
    $contributor->forceFill(['status' => UserStatus::Suspended])->save();

    $this->actingAs($contributor)->get('/__probe/active-only')->assertForbidden();
});

it('refuses guests, clients and admins on guarded areas', function () {
    Route::middleware(['web', 'active.contributor'])->get('/__probe/active-only', fn () => 'inside');

    $this->get('/__probe/active-only')->assertForbidden();
    $this->actingAs(clientUser())->get('/__probe/active-only')->assertForbidden();
    $this->actingAs(adminUser())->get('/__probe/active-only')->assertForbidden();
});

it('keeps the dashboard, onboarding and profile open to non-active contributors', function (OnboardingState $state) {
    $contributor = contributorIn($state);

    foreach (['/app/contributor', '/app/contributor/onboarding', '/app/contributor/profile'] as $path) {
        $this->actingAs($contributor)->get($path)->assertOk();
    }
})->with([OnboardingState::Registered, OnboardingState::PendingReview, OnboardingState::Rejected, OnboardingState::Suspended]);

it('applies auth behavior to every contributor route: guest, wrong role, right role', function (string $path) {
    $this->get($path)->assertRedirect('/login');
    $this->actingAs(clientUser())->get($path)->assertForbidden();
    $this->actingAs(adminUser())->get($path)->assertForbidden();
    $this->actingAs(contributorIn(OnboardingState::Registered, ['photo_path' => 'profile-photos/none.jpg']))->get($path)->assertStatus($path === '/app/contributor/profile/photo' ? 404 : 200);
})->with(['/app/contributor', '/app/contributor/onboarding', '/app/contributor/profile', '/app/contributor/profile/photo']);

it('sends an unverified contributor to verify their email first', function () {
    $contributor = contributorIn(OnboardingState::Registered, [], ['email_verified_at' => null]);

    $this->actingAs($contributor)->get('/app/contributor/onboarding')->assertRedirect(route('verification.notice'));
});
