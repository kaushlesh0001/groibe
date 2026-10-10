<?php

use App\Actions\Contributors\AcceptMemberAgreement;
use App\Enums\AdminRole;
use App\Enums\OnboardingState;
use App\Enums\UserStatus;
use App\Filament\Admin\Resources\Contributors\ContributorResource;
use App\Filament\Admin\Resources\Contributors\Pages\ListContributors;
use App\Filament\Admin\Resources\Contributors\Pages\ViewContributor;
use App\Models\Profile;
use App\Models\Skill;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    publishAgreements();
    Notification::fake();
    $this->community = adminUser(AdminRole::CommunityManager);
});

function pendingWithAgreement()
{
    $contributor = contributorIn(OnboardingState::PendingReview);
    app(AcceptMemberAgreement::class)($contributor);

    return $contributor;
}

it('opens the list for admins who may read contributors and refuses everyone else', function () {
    foreach ([AdminRole::SuperAdmin, AdminRole::CommunityManager, AdminRole::DeliveryManager, AdminRole::FinanceOps] as $role) {
        asUser(adminUser($role))->get(ContributorResource::getUrl('index'))->assertOk();
    }

    asUser(contributorIn(OnboardingState::Active))->get(ContributorResource::getUrl('index'))->assertForbidden();
    asUser(clientUser())->get(ContributorResource::getUrl('index'))->assertForbidden();
});

it('never creates contributors from the admin panel', function () {
    expect(ContributorResource::canCreate())->toBeFalse();
});

it('shows the review queue by default and keeps client profiles out of every tab', function () {
    $pending = contributorIn(OnboardingState::PendingReview);
    $active = contributorIn(OnboardingState::Active);
    $clientProfile = Profile::factory()->create(['user_id' => clientUser()->id, 'onboarding_state' => OnboardingState::PendingReview]);

    Livewire::actingAs($this->community)->test(ListContributors::class)
        ->assertCanSeeTableRecords([$pending->profile])
        ->assertCanNotSeeTableRecords([$active->profile, $clientProfile])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$pending->profile, $active->profile])
        ->assertCanNotSeeTableRecords([$clientProfile]);
});

it('approves from the queue, records it and notifies the contributor', function () {
    $contributor = pendingWithAgreement();

    Livewire::actingAs($this->community)->test(ListContributors::class)
        ->callAction(TestAction::make('approve')->table($contributor->profile))
        ->assertNotified('Contributor approved');

    expect($contributor->profile->refresh()->onboarding_state)->toBe(OnboardingState::Active)
        ->and($contributor->refresh()->status)->toBe(UserStatus::Active);

    expect(Activity::query()->where('log_name', 'contributor')->where('event', 'active')->exists())->toBeTrue();
});

it('shows a readable message instead of failing when the Member Agreement gate is not met', function () {
    $contributor = contributorIn(OnboardingState::PendingReview);

    Livewire::actingAs($this->community)->test(ListContributors::class)
        ->callAction(TestAction::make('approve')->table($contributor->profile))
        ->assertNotified('That did not go through');

    expect($contributor->profile->refresh()->onboarding_state)->toBe(OnboardingState::PendingReview);
});

it('rejects with a required internal reason', function () {
    $contributor = contributorIn(OnboardingState::PendingReview);

    Livewire::actingAs($this->community)->test(ListContributors::class)
        ->callAction(TestAction::make('reject')->table($contributor->profile), ['reason' => ''])
        ->assertHasFormErrors(['reason' => 'required']);

    Livewire::actingAs($this->community)->test(ListContributors::class)
        ->callAction(TestAction::make('reject')->table($contributor->profile), ['reason' => 'Not a fit'])
        ->assertNotified('Contributor rejected');

    expect($contributor->profile->refresh()->onboarding_state)->toBe(OnboardingState::Rejected);
});

it('suspends and reinstates from the page header', function () {
    $contributor = contributorIn(OnboardingState::Active);

    Livewire::actingAs($this->community)->test(ViewContributor::class, ['record' => $contributor->profile->getKey()])
        ->callAction('suspend', ['reason' => 'Breach'])
        ->assertNotified('Contributor suspended');

    expect($contributor->refresh()->status)->toBe(UserStatus::Suspended);

    Livewire::actingAs($this->community)->test(ViewContributor::class, ['record' => $contributor->profile->getKey()])
        ->callAction('reinstate', ['reason' => null])
        ->assertNotified('Contributor reinstated');

    expect($contributor->profile->refresh()->onboarding_state)->toBe(OnboardingState::Active)
        ->and($contributor->refresh()->status)->toBe(UserStatus::Active);
});

it('only offers each action in the state where it is legal', function () {
    $pending = contributorIn(OnboardingState::PendingReview);
    $active = contributorIn(OnboardingState::Active);
    $suspended = contributorIn(OnboardingState::Suspended);
    $rejected = contributorIn(OnboardingState::Rejected);

    $list = Livewire::actingAs($this->community)->test(ListContributors::class)->set('activeTab', 'all');

    $list->assertActionVisible(TestAction::make('approve')->table($pending->profile))
        ->assertActionHidden(TestAction::make('approve')->table($active->profile))
        ->assertActionVisible(TestAction::make('suspend')->table($active->profile))
        ->assertActionHidden(TestAction::make('suspend')->table($pending->profile))
        ->assertActionVisible(TestAction::make('reinstate')->table($suspended->profile))
        ->assertActionHidden(TestAction::make('reinstate')->table($rejected->profile))
        ->assertActionHidden(TestAction::make('approve')->table($rejected->profile))
        ->assertActionHidden(TestAction::make('reinstate')->table($pending->profile));
});

it('hides every review action from a delivery manager, who may only read and update', function () {
    $pending = contributorIn(OnboardingState::PendingReview);
    $delivery = adminUser(AdminRole::DeliveryManager);

    Livewire::actingAs($delivery)->test(ListContributors::class)
        ->assertActionHidden(TestAction::make('approve')->table($pending->profile))
        ->assertActionHidden(TestAction::make('reject')->table($pending->profile));

    Livewire::actingAs($delivery)->test(ViewContributor::class, ['record' => $pending->profile->getKey()])
        ->assertActionHidden('verifySkills');

    expect($pending->profile->refresh()->onboarding_state)->toBe(OnboardingState::PendingReview);
});

it('verifies and un-verifies skills and keeps the original verifier when nothing changes', function () {
    $contributor = contributorIn(OnboardingState::Active);
    [$a, $b] = Skill::factory()->count(2)->create();
    $contributor->skills()->attach([$a->id => ['level' => 'expert'], $b->id => ['level' => 'advanced']]);

    Livewire::actingAs($this->community)->test(ViewContributor::class, ['record' => $contributor->profile->getKey()])
        ->callAction('verifySkills', ['verified' => [$a->id]])
        ->assertNotified('Skill verification saved');

    $pivotA = fn () => $contributor->skills()->whereKey($a->id)->first()->pivot;

    expect((bool) $pivotA()->is_verified)->toBeTrue()
        ->and($pivotA()->verified_by)->toBe($this->community->id)
        ->and((bool) $contributor->skills()->whereKey($b->id)->first()->pivot->is_verified)->toBeFalse();

    $other = adminUser();
    Livewire::actingAs($other)->test(ViewContributor::class, ['record' => $contributor->profile->getKey()])
        ->callAction('verifySkills', ['verified' => [$a->id]]);

    expect($pivotA()->verified_by)->toBe($this->community->id);

    Livewire::actingAs($this->community)->test(ViewContributor::class, ['record' => $contributor->profile->getKey()])
        ->callAction('verifySkills', ['verified' => []]);

    expect((bool) $pivotA()->is_verified)->toBeFalse();
});

it('suspends and reinstates in bulk, skipping records in the wrong state and reporting the count', function () {
    $active = contributorIn(OnboardingState::Active);
    $pending = contributorIn(OnboardingState::PendingReview);

    Livewire::actingAs($this->community)->test(ListContributors::class)
        ->set('activeTab', 'all')
        ->callTableBulkAction('suspendSelected', [$active->profile, $pending->profile], ['reason' => 'Audit'])
        ->assertNotified('1 suspended, 1 skipped');

    expect($active->profile->refresh()->onboarding_state)->toBe(OnboardingState::Suspended)
        ->and($pending->profile->refresh()->onboarding_state)->toBe(OnboardingState::PendingReview);

    Livewire::actingAs($this->community)->test(ListContributors::class)
        ->set('activeTab', 'all')
        ->callTableBulkAction('reinstateSelected', [$active->profile])
        ->assertNotified('1 reinstated');

    expect($active->profile->refresh()->onboarding_state)->toBe(OnboardingState::Active);
});

it('refuses bulk review actions for a delivery manager without changing anything', function () {
    $active = contributorIn(OnboardingState::Active);

    Livewire::actingAs(adminUser(AdminRole::DeliveryManager))->test(ListContributors::class)
        ->set('activeTab', 'all')
        ->callTableBulkAction('suspendSelected', [$active->profile], ['reason' => 'x'])
        ->assertNotified('0 suspended, 1 skipped');

    expect($active->profile->refresh()->onboarding_state)->toBe(OnboardingState::Active);
});

it('issues the same number of queries for 3 contributors as for 10 on the admin list', function () {
    $make = function (int $n) {
        foreach (range(1, $n) as $i) {
            contributorIn(OnboardingState::PendingReview);
        }
    };

    $measure = function (): int {
        Livewire::actingAs($this->community)->test(ListContributors::class); // warm
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->community)->test(ListContributors::class);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $make(3);
    $small = $measure();
    $make(7);
    $large = $measure();

    expect($large)->toBe($small);
});
