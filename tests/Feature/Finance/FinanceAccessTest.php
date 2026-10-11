<?php

use App\Enums\AdminRole;
use App\Enums\InvoiceStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\PayoutStatus;
use App\Enums\ProjectStatus;
use App\Filament\Admin\Resources\InvoiceRefs\InvoiceRefResource;
use App\Filament\Admin\Resources\InvoiceRefs\Pages\ListInvoiceRefs;
use App\Filament\Admin\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Admin\Resources\Payouts\PayoutResource;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\RelationManagers\AssignmentsRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\InvoicesRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\PayoutsRelationManager;
use App\Livewire\Contributor\Payouts;
use App\Models\Assignment;
use App\Models\InvoiceRef;
use App\Models\Payout;
use App\Models\Project;
use Livewire\Livewire;

beforeEach(function () {
    $this->finance = adminUser(AdminRole::FinanceOps);
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->community = adminUser(AdminRole::CommunityManager);
    $this->super = adminUser(AdminRole::SuperAdmin);

    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $this->dm->id]);
    $this->owner = clientUser($this->project->organization, OrgRole::Owner);
    $this->me = contributorIn(OnboardingState::Active);
    $this->other = contributorIn(OnboardingState::Active);
    $this->mySeat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->me->id, 'agreed_amount' => 50000]);
    $this->otherSeat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->other->id, 'agreed_amount' => 50000]);

    $this->planned = Payout::factory()->status(PayoutStatus::Planned)->create(['assignment_id' => $this->mySeat->id, 'amount' => 1000]);
    $this->approved = Payout::factory()->status(PayoutStatus::Approved)->create(['assignment_id' => $this->mySeat->id, 'amount' => 2000]);
    $this->paid = Payout::factory()->status(PayoutStatus::Paid)->create(['assignment_id' => $this->mySeat->id, 'amount' => 3000, 'tds_amount' => 300, 'reference' => 'UTRMINE12345']);
    $this->theirs = Payout::factory()->status(PayoutStatus::Approved)->create(['assignment_id' => $this->otherSeat->id, 'amount' => 7777]);
    $this->invoice = InvoiceRef::factory()->status(InvoiceStatus::Sent)->create(['project_id' => $this->project->id]);
});

describe('the permission matrix', function () {
    it('gives each role exactly its payout and invoice rights', function (AdminRole $role, array $payouts, array $invoices, bool $export) {
        $admin = adminUser($role);
        $can = fn (string $permission) => $admin->can($permission);

        foreach (['view', 'create', 'update', 'delete'] as $ability) {
            expect($can("payouts.{$ability}"))->toBe(in_array($ability, $payouts, true), "payouts.{$ability}")
                ->and($can("invoice_refs.{$ability}"))->toBe(in_array($ability, $invoices, true), "invoice_refs.{$ability}");
        }

        expect($can('import_export.payouts'))->toBe($export);
    })->with([
        [AdminRole::FinanceOps, ['view', 'create', 'update', 'delete'], ['view', 'create', 'update', 'delete'], true],
        [AdminRole::SuperAdmin, ['view', 'create', 'update', 'delete'], ['view', 'create', 'update', 'delete'], true],
        [AdminRole::DeliveryManager, ['view'], ['view'], false],
        [AdminRole::CommunityManager, [], [], false],
    ]);
});

describe('clients and strangers', function () {
    it('give clients no policy access to payouts or invoice references', function () {
        foreach ([$this->owner, clientUser($this->project->organization, OrgRole::Manager)] as $client) {
            expect($client->can('viewAny', Payout::class))->toBeFalse()
                ->and($client->can('view', $this->approved))->toBeFalse()
                ->and($client->can('viewAny', InvoiceRef::class))->toBeFalse()
                ->and($client->can('view', $this->invoice))->toBeFalse()
                ->and($client->can('export', Payout::class))->toBeFalse();
        }
    });

    it('keeps clients and guests out of the contributor payouts page and the admin screens', function () {
        $this->get(route('contributor.payouts'))->assertRedirect(route('login'));
        $this->actingAs($this->owner)->get(route('contributor.payouts'))->assertForbidden();
        $this->actingAs($this->owner)->get(PayoutResource::getUrl('index'))->assertForbidden();
        $this->actingAs($this->owner)->get(InvoiceRefResource::getUrl('index'))->assertForbidden();
    });

    it('never puts money in a client page', function () {
        $this->actingAs($this->owner)->get(route('client.projects.show', $this->project))
            ->assertOk()->assertDontSee('7,777')->assertDontSee('7777')->assertDontSee('UTRMINE12345')->assertDontSee($this->invoice->zoho_invoice_ref);
    });
});

describe('the admin screens', function () {
    it('lets finance, super admin and delivery managers open payouts and invoice references, and nobody else', function (string $who, bool $allowed) {
        $this->actingAs($this->{$who});

        $payouts = $this->get(PayoutResource::getUrl('index'));
        $invoices = $this->get(InvoiceRefResource::getUrl('index'));

        $allowed ? $payouts->assertOk() : $payouts->assertForbidden();
        $allowed ? $invoices->assertOk() : $invoices->assertForbidden();
    })->with([['finance', true], ['super', true], ['dm', true], ['community', false]]);

    it('shows the delivery manager the rows but none of the write actions', function () {
        Livewire::actingAs($this->dm)->test(ListPayouts::class)
            ->assertCanSeeTableRecords([$this->planned, $this->approved, $this->paid])
            ->assertActionHidden('plan')->assertActionHidden('export')
            ->assertTableActionHidden('approve', $this->planned)
            ->assertTableActionHidden('markPaid', $this->approved)
            ->assertTableActionHidden('cancel', $this->planned)
            ->assertTableActionHidden('edit', $this->planned)
            ->assertTableActionHidden('delete', $this->planned);

        Livewire::actingAs($this->dm)->test(ListInvoiceRefs::class)
            ->assertCanSeeTableRecords([$this->invoice])->assertActionHidden('create')->assertActionHidden('export')
            ->assertTableActionHidden('paid', $this->invoice);
    });

    it('shows finance each action only for the statuses it applies to', function () {
        Livewire::actingAs($this->finance)->test(ListPayouts::class)
            ->assertTableActionVisible('approve', $this->planned)->assertTableActionHidden('approve', $this->approved)
            ->assertTableActionVisible('markPaid', $this->approved)->assertTableActionHidden('markPaid', $this->planned)->assertTableActionHidden('markPaid', $this->paid)
            ->assertTableActionVisible('cancel', $this->approved)->assertTableActionHidden('cancel', $this->paid)
            ->assertTableActionVisible('edit', $this->planned)->assertTableActionHidden('edit', $this->approved)
            ->assertTableActionVisible('delete', $this->planned)->assertTableActionHidden('delete', $this->approved);
    });

    it('runs the payout steps from the screen', function () {
        $page = Livewire::actingAs($this->finance)->test(ListPayouts::class);

        $page->callTableAction('approve', $this->planned)->assertNotified('Payout approved');
        expect($this->planned->fresh()->status)->toBe(PayoutStatus::Approved);

        $page->callTableAction('markPaid', $this->approved, ['reference' => 'UTRABC123456', 'paid_on' => now()->toDateString(), 'tds' => '200'])->assertNotified('Payout marked paid');
        expect($this->approved->fresh()->status)->toBe(PayoutStatus::Paid)->and($this->approved->fresh()->reference)->toBe('UTRABC123456');

        $page->callTableAction('cancel', $this->planned, ['reason' => 'Changed mind']);
        expect($this->planned->fresh()->status)->toBe(PayoutStatus::Cancelled);
    });

    it('plans a payout from the screen, and explains a refusal instead of failing', function () {
        $page = Livewire::actingAs($this->finance)->test(ListPayouts::class);

        $page->callAction('plan', ['assignment_id' => $this->otherSeat->id, 'amount' => '500'])->assertNotified('Payout planned');
        expect(Payout::query()->where('assignment_id', $this->otherSeat->id)->where('amount', 500)->exists())->toBeTrue();

        $page->callAction('plan', ['assignment_id' => $this->otherSeat->id, 'amount' => '999999'])->assertNotified('That did not go through');
    });

    it('refuses a seat without an agreed amount even if its id is posted', function () {
        $noAmount = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'agreed_amount' => null]);

        Livewire::actingAs($this->finance)->test(ListPayouts::class)
            ->callAction('plan', ['assignment_id' => $noAmount->id])->assertNotified('That did not go through');

        expect(Payout::query()->where('assignment_id', $noAmount->id)->exists())->toBeFalse();
    });

    it('shows the Payouts and Invoices tabs on a project only to staff who may read them', function () {
        foreach ([[$this->dm, true], [$this->finance, true], [$this->community, false]] as [$admin, $visible]) {
            $this->actingAs($admin);

            expect(PayoutsRelationManager::canViewForRecord($this->project, EditProject::class))->toBe($visible)
                ->and(InvoicesRelationManager::canViewForRecord($this->project, EditProject::class))->toBe($visible);
        }

        Livewire::actingAs($this->dm)->test(PayoutsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
            ->assertCanSeeTableRecords([$this->planned, $this->approved, $this->paid])->assertTableActionDoesNotExist('approve');
    });

    it('lets a delivery manager set the agreed amount on the Team tab but not finance', function () {
        Livewire::actingAs($this->dm)->test(AssignmentsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
            ->assertTableActionVisible('setAmount', $this->mySeat)
            ->callTableAction('setAmount', $this->mySeat, ['agreed_amount' => '60000'])->assertNotified('Agreed amount saved');

        expect((string) $this->mySeat->fresh()->agreed_amount)->toBe('60000.00');

        Livewire::actingAs($this->finance)->test(AssignmentsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
            ->assertTableActionHidden('setAmount', $this->mySeat);
    });
});

describe('the contributor payouts page', function () {
    it('shows my approved, paid and cancelled-after-approval payouts, and nothing planned', function () {
        $cancelledAfter = Payout::factory()->status(PayoutStatus::Cancelled)->create(['assignment_id' => $this->mySeat->id, 'amount' => 4444, 'approved_at' => now()]);
        $cancelledBefore = Payout::factory()->status(PayoutStatus::Cancelled)->create(['assignment_id' => $this->mySeat->id, 'amount' => 5555]);

        Livewire::actingAs($this->me)->test(Payouts::class)
            ->assertSee('2,000.00')->assertSee('3,000.00')->assertSee('UTRMINE12345')->assertSee('2,700.00')
            ->assertSee('4,444.00')
            ->assertDontSee('1,000.00')->assertDontSee('5,555.00');

        expect($cancelledBefore->approved_at)->toBeNull()->and($cancelledAfter->approved_at)->not->toBeNull();
    });

    it('never shows another contributor\'s payouts, internal notes or the creator', function () {
        $this->approved->update(['notes' => 'Internal haggling note']);

        Livewire::actingAs($this->me)->test(Payouts::class)
            ->assertDontSee('7,777')->assertDontSee('Internal haggling note')->assertDontSee($this->finance->name);

        Livewire::actingAs($this->other)->test(Payouts::class)->assertSee('7,777')->assertDontSee('2,000.00')->assertDontSee('UTRMINE12345');
    });

    it('is open to active contributors only', function () {
        $this->actingAs($this->me)->get(route('contributor.payouts'))->assertOk();

        foreach ([OnboardingState::PendingReview, OnboardingState::Suspended] as $state) {
            $this->actingAs(contributorIn($state))->get(route('contributor.payouts'))->assertForbidden();
        }
    });

    it('applies the policy per payout', function () {
        expect($this->me->can('view', $this->approved))->toBeTrue()
            ->and($this->me->can('view', $this->paid))->toBeTrue()
            ->and($this->me->can('view', $this->planned))->toBeFalse()
            ->and($this->me->can('view', $this->theirs))->toBeFalse()
            ->and($this->me->can('create', Payout::class))->toBeFalse()
            ->and($this->me->can('update', $this->approved))->toBeFalse()
            ->and($this->me->can('viewAny', InvoiceRef::class))->toBeFalse()
            ->and($this->me->can('export', Payout::class))->toBeFalse();
    });

    it('queries a flat number of times however many payouts there are', function () {
        Payout::factory()->count(12)->status(PayoutStatus::Approved)->create(['assignment_id' => $this->mySeat->id, 'amount' => 1]);

        // Warm up once so one-off work (session, permissions) is not counted.
        Livewire::actingAs($this->me)->test(Payouts::class);

        DB::enableQueryLog();
        Livewire::actingAs($this->me)->test(Payouts::class);
        $few = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        Payout::factory()->count(3)->status(PayoutStatus::Approved)->create(['assignment_id' => $this->mySeat->id, 'amount' => 1]);

        DB::enableQueryLog();
        Livewire::actingAs($this->me)->test(Payouts::class);

        expect(count(DB::getQueryLog()))->toBe($few);
    });
});

it('shows the contributor and navigation link only to active contributors', function () {
    $this->actingAs($this->me)->get(route('contributor.dashboard'))->assertSee(route('contributor.payouts'), false);
    $this->actingAs(contributorIn(OnboardingState::PendingReview))->get(route('contributor.dashboard'))->assertDontSee(route('contributor.payouts'), false);
});
