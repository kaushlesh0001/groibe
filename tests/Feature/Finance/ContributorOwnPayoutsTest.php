<?php

use App\Enums\AdminRole;
use App\Enums\AssignmentStatus;
use App\Enums\OnboardingState;
use App\Enums\PayoutStatus;
use App\Enums\ProjectStatus;
use App\Filament\Admin\Resources\InvoiceRefs\InvoiceRefResource;
use App\Filament\Admin\Resources\Payouts\PayoutResource;
use App\Livewire\Contributor\Payouts;
use App\Models\Assignment;
use App\Models\Payout;
use App\Models\Project;
use Livewire\Livewire;

/*
 * A contributor reads their own approved money and nothing else: not a colleague's on the same project, not their own
 * plans, not another project's, and not the admin screens.
 */
beforeEach(function () {
    $this->me = contributorIn(OnboardingState::Active);
    $this->colleague = contributorIn(OnboardingState::Active);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create();

    $this->mySeat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->me->id, 'agreed_amount' => 900000]);
    $this->colleagueSeat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->colleague->id, 'agreed_amount' => 900000]);

    $make = fn (Assignment $seat, PayoutStatus $status, int $amount, array $extra = []) => Payout::factory()->status($status)->create(['assignment_id' => $seat->id, 'amount' => $amount, ...$extra]);

    $this->mineApproved = $make($this->mySeat, PayoutStatus::Approved, 11111);
    $this->minePaid = $make($this->mySeat, PayoutStatus::Paid, 22222, ['reference' => 'UTRMINE000111']);
    $this->minePlanned = $make($this->mySeat, PayoutStatus::Planned, 33333);
    $this->theirsApproved = $make($this->colleagueSeat, PayoutStatus::Approved, 44444);
    $this->theirsPaid = $make($this->colleagueSeat, PayoutStatus::Paid, 55555, ['reference' => 'UTRTHEIRS00999']);
});

it('lists exactly my approved and paid payouts', function () {
    Livewire::actingAs($this->me)->test(Payouts::class)
        ->assertSee('11,111.00')->assertSee('22,222.00')->assertSee('UTRMINE000111')
        ->assertDontSee('33,333.00')
        ->assertDontSee('44,444.00')->assertDontSee('55,555.00')->assertDontSee('UTRTHEIRS00999');

    Livewire::actingAs($this->colleague)->test(Payouts::class)
        ->assertSee('44,444.00')->assertSee('55,555.00')
        ->assertDontSee('11,111.00')->assertDontSee('22,222.00')->assertDontSee('UTRMINE000111');
});

it('returns only my rows from the query behind the page', function () {
    expect(Payout::query()->visibleToContributor($this->me)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->mineApproved, $this->minePaid])->pluck('id')->sort()->values()->all());
});

it('still shows my approved payout after my seat is completed or removed', function (AssignmentStatus $status) {
    $this->mySeat->forceFill(['status' => $status])->save();

    Livewire::actingAs($this->me)->test(Payouts::class)->assertSee('11,111.00')->assertSee('22,222.00');

    expect($this->me->can('view', $this->mineApproved))->toBeTrue();
})->with([AssignmentStatus::Completed, AssignmentStatus::Removed]);

it('pages through only my rows when there are many', function () {
    Payout::factory()->count(18)->status(PayoutStatus::Approved)->create(['assignment_id' => $this->mySeat->id, 'amount' => 1]);
    Payout::factory()->count(5)->status(PayoutStatus::Approved)->create(['assignment_id' => $this->colleagueSeat->id, 'amount' => 2]);

    Livewire::actingAs($this->me)->test(Payouts::class)
        ->assertViewHas('payouts', fn ($page) => $page->total() === 20 && $page->getCollection()->every(fn (Payout $p) => $p->user_id === $this->me->id));
});

it('refuses me the policy abilities on anyone else\'s payout and on every write', function () {
    foreach ([$this->theirsApproved, $this->theirsPaid, $this->minePlanned] as $payout) {
        expect($this->me->can('view', $payout))->toBeFalse();
    }

    foreach ([$this->mineApproved, $this->theirsApproved] as $payout) {
        foreach (['update', 'approve', 'markPaid', 'cancel', 'delete'] as $ability) {
            expect($this->me->can($ability, $payout))->toBeFalse($ability);
        }
    }

    expect($this->me->can('create', Payout::class))->toBeFalse()
        ->and($this->me->can('export', Payout::class))->toBeFalse()
        ->and($this->me->can('setAmount', $this->mySeat))->toBeFalse();
});

it('keeps me out of the admin finance screens and the invoice references', function () {
    $this->actingAs($this->me)->get(PayoutResource::getUrl('index'))->assertForbidden();
    $this->actingAs($this->me)->get(InvoiceRefResource::getUrl('index'))->assertForbidden();
});

it('is closed to contributors who are not active, and to guests', function (OnboardingState $state) {
    $this->actingAs(contributorIn($state))->get(route('contributor.payouts'))->assertForbidden();
})->with([OnboardingState::PendingReview, OnboardingState::ProfileComplete, OnboardingState::Suspended]);

it('is closed to guests', function () {
    $this->get(route('contributor.payouts'))->assertRedirect(route('login'));
});

it('never shows the internal note, the planner or an invoice, even for my own payout', function () {
    $planner = adminUser(AdminRole::FinanceOps);
    $this->mineApproved->forceFill(['notes' => 'Private finance note', 'created_by' => $planner->id])->save();

    Livewire::actingAs($this->me)->test(Payouts::class)->assertDontSee('Private finance note')->assertDontSee($planner->name)->assertDontSee('Invoice');
});
