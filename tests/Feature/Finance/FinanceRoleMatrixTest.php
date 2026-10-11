<?php

use App\Enums\AdminRole;
use App\Enums\InvoiceStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\PayoutStatus;
use App\Models\Assignment;
use App\Models\InvoiceRef;
use App\Models\Payout;
use App\Models\User;
use Spatie\Permission\Models\Role;

/*
 * One table for the whole finance surface: every kind of person against every ability on a payout and on an invoice
 * reference. The payout used belongs to somebody else, so a contributor's "own payout" right does not show up here
 * (that is covered in ContributorOwnPayoutsTest).
 */
const PAYOUT_ABILITIES = ['viewAny', 'view', 'create', 'update', 'approve', 'markPaid', 'cancel', 'delete', 'deleteAny', 'export'];
const INVOICE_ABILITIES = ['viewAny', 'view', 'create', 'update', 'delete', 'export'];

beforeEach(function () {
    $this->payout = Payout::factory()->status(PayoutStatus::Approved)->create();
    $this->invoice = InvoiceRef::factory()->status(InvoiceStatus::Sent)->create();

    $this->person = fn (string $who): User => match ($who) {
        'finance_ops' => adminUser(AdminRole::FinanceOps),
        'super_admin' => adminUser(AdminRole::SuperAdmin),
        'delivery_manager' => adminUser(AdminRole::DeliveryManager),
        'community_manager' => adminUser(AdminRole::CommunityManager),
        'contributor' => contributorIn(OnboardingState::Active),
        'client_owner' => clientUser(null, OrgRole::Owner),
    };

    $this->check = function (User $user, mixed $subject, array $abilities, array $allowed): array {
        $result = [];

        foreach ($abilities as $ability) {
            $arguments = in_array($ability, ['viewAny', 'create', 'deleteAny', 'export'], true) ? $subject::class : $subject;
            $result[$ability] = $user->can($ability, $arguments);
        }

        return $result + ['_allowed' => $allowed];
    };
});

it('gives each kind of person exactly the right abilities on a payout', function (string $who, array $allowed) {
    $granted = collect(($this->check)(($this->person)($who), $this->payout, PAYOUT_ABILITIES, $allowed))->except('_allowed')->filter()->keys()->sort()->values()->all();

    expect($granted)->toBe(collect($allowed)->sort()->values()->all());
})->with([
    'finance ops' => ['finance_ops', ['viewAny', 'view', 'create', 'update', 'approve', 'markPaid', 'cancel', 'delete', 'deleteAny', 'export']],
    'super admin' => ['super_admin', ['viewAny', 'view', 'create', 'update', 'approve', 'markPaid', 'cancel', 'delete', 'deleteAny', 'export']],
    'delivery manager' => ['delivery_manager', ['viewAny', 'view']],
    'community manager' => ['community_manager', []],
    'contributor (someone else\'s payout)' => ['contributor', ['viewAny']],
    'client owner' => ['client_owner', []],
]);

it('gives each kind of person exactly the right abilities on an invoice reference', function (string $who, array $allowed) {
    $granted = collect(($this->check)(($this->person)($who), $this->invoice, INVOICE_ABILITIES, $allowed))->except('_allowed')->filter()->keys()->sort()->values()->all();

    expect($granted)->toBe(collect($allowed)->sort()->values()->all());
})->with([
    'finance ops' => ['finance_ops', ['viewAny', 'view', 'create', 'update', 'delete', 'export']],
    'super admin' => ['super_admin', ['viewAny', 'view', 'create', 'update', 'delete', 'export']],
    'delivery manager' => ['delivery_manager', ['viewAny', 'view']],
    'community manager' => ['community_manager', []],
    'contributor' => ['contributor', []],
    'client owner' => ['client_owner', []],
]);

it('denies every finance ability to a suspended admin and a suspended contributor', function () {
    $suspendedFinance = adminUser(AdminRole::FinanceOps);
    $suspendedFinance->forceFill(['status' => 'suspended'])->save();

    $suspendedContributor = contributorIn(OnboardingState::Suspended);
    $ownApproved = Payout::factory()->status(PayoutStatus::Approved)->create([
        'assignment_id' => Assignment::factory()->active()->create(['user_id' => $suspendedContributor->id])->id,
    ]);

    foreach (['viewAny', 'create', 'export'] as $ability) {
        expect($suspendedFinance->can($ability, Payout::class))->toBeFalse();
    }

    expect($suspendedFinance->can('update', $this->payout))->toBeFalse()
        ->and($suspendedContributor->can('view', $ownApproved))->toBeFalse()
        ->and($suspendedContributor->can('viewAny', Payout::class))->toBeFalse();
});

it('holds the same split in the stored permissions, so a role edited in Admin cannot drift from the policies', function () {
    $matrix = [
        AdminRole::FinanceOps->value => ['payouts.view', 'payouts.create', 'payouts.update', 'payouts.delete', 'invoice_refs.view', 'invoice_refs.create', 'invoice_refs.update', 'invoice_refs.delete', 'import_export.payouts'],
        AdminRole::DeliveryManager->value => ['payouts.view', 'invoice_refs.view'],
        AdminRole::CommunityManager->value => [],
    ];

    foreach ($matrix as $role => $expected) {
        $held = Role::findByName($role, 'web')->permissions->pluck('name')
            ->filter(fn (string $name) => str_starts_with($name, 'payouts.') || str_starts_with($name, 'invoice_refs.') || $name === 'import_export.payouts')
            ->reject(fn (string $name) => in_array($name, ['payouts.approve', 'invoice_refs.approve'], true))
            ->sort()->values()->all();

        expect($held)->toBe(collect($expected)->sort()->values()->all(), $role);
    }
});
