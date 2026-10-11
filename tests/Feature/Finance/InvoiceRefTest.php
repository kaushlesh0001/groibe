<?php

use App\Actions\Invoices\DeleteInvoiceRef;
use App\Actions\Invoices\MarkInvoiceOverdue;
use App\Actions\Invoices\MarkInvoicePaid;
use App\Actions\Invoices\SaveInvoiceRef;
use App\Actions\Invoices\SendInvoiceRef;
use App\Enums\AdminRole;
use App\Enums\InvoiceStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Filament\Admin\Resources\InvoiceRefs\Pages\ListInvoiceRefs;
use App\Models\InvoiceRef;
use App\Models\Project;
use App\Notifications\InvoicesOverdue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();

    $this->finance = adminUser(AdminRole::FinanceOps);
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['contract_value' => 100000]);
    $this->save = fn (array $data = [], ?InvoiceRef $invoice = null, $actor = null) => app(SaveInvoiceRef::class)(
        $actor ?? $this->finance,
        $this->project,
        ['zoho_invoice_ref' => 'INV-001', 'amount' => '50000', ...$data],
        $invoice,
    );
});

describe('creating and editing', function () {
    it('starts as a draft with the amount and dates', function () {
        $invoice = ($this->save)(['issued_at' => '2026-10-01', 'due_at' => '2026-10-31']);

        expect($invoice->status)->toBe(InvoiceStatus::Draft)
            ->and((string) $invoice->amount)->toBe('50000.00')
            ->and($invoice->currency)->toBe('INR')
            ->and($invoice->due_at->toDateString())->toBe('2026-10-31');

        expect(Activity::query()->where('log_name', 'invoice')->where('event', 'created')->exists())->toBeTrue();
    });

    it('keeps one Zoho number per project but allows the same number on another project', function () {
        ($this->save)();

        expect(fn () => ($this->save)())->toThrow(ActionBlocked::class, 'already has an invoice');

        expect(app(SaveInvoiceRef::class)($this->finance, Project::factory()->create(), ['zoho_invoice_ref' => 'INV-001', 'amount' => '1']))
            ->toBeInstanceOf(InvoiceRef::class);
    });

    it('validates the reference, amount and dates', function (array $data, string $message) {
        expect(fn () => ($this->save)($data))->toThrow(ActionBlocked::class, $message);
    })->with([
        'blank reference' => [['zoho_invoice_ref' => '  '], 'Zoho invoice number'],
        'long reference' => [['zoho_invoice_ref' => str_repeat('x', 101)], 'Zoho invoice number'],
        'zero amount' => [['amount' => '0'], 'more than zero'],
        'bad amount' => [['amount' => 'abc'], 'number'],
        'bad date' => [['due_at' => 'someday'], 'due date'],
        'due before issue' => [['issued_at' => '2026-10-10', 'due_at' => '2026-10-01'], 'before the issue date'],
    ]);

    it('edits a draft, a sent and an overdue invoice but never a paid one', function () {
        $invoice = ($this->save)(['due_at' => '2026-10-31']);
        expect((string) ($this->save)(['amount' => '60000', 'due_at' => '2026-10-31'], $invoice)->amount)->toBe('60000.00');

        foreach ([InvoiceStatus::Sent, InvoiceStatus::Overdue] as $status) {
            $invoice->forceFill(['status' => $status])->save();
            expect((string) ($this->save)(['amount' => '61000', 'due_at' => '2026-10-31'], $invoice)->amount)->toBe('61000.00');
        }

        $invoice->forceFill(['status' => InvoiceStatus::Paid])->save();
        expect(fn () => ($this->save)(['amount' => '1'], $invoice))->toThrow(ActionBlocked::class, 'paid invoice');
    });

    it('warns when invoices pass the contract value but does not block', function () {
        ($this->save)(['amount' => '60000']);
        expect(SaveInvoiceRef::exceedsContractValue($this->project))->toBeFalse();

        ($this->save)(['zoho_invoice_ref' => 'INV-002', 'amount' => '60000']);
        expect(SaveInvoiceRef::exceedsContractValue($this->project))->toBeTrue();

        $noValue = Project::factory()->create(['contract_value' => null]);
        expect(SaveInvoiceRef::exceedsContractValue($noValue))->toBeFalse();
    });

    it('is finance and super admin to write, delivery manager to read only', function () {
        expect(fn () => ($this->save)([], null, $this->dm))->toThrow(AuthorizationException::class)
            ->and(fn () => ($this->save)([], null, adminUser(AdminRole::CommunityManager)))->toThrow(AuthorizationException::class)
            ->and(fn () => ($this->save)([], null, clientUser()))->toThrow(AuthorizationException::class)
            ->and(fn () => ($this->save)([], null, contributorUser()))->toThrow(AuthorizationException::class);

        expect(($this->save)([], null, adminUser(AdminRole::SuperAdmin)))->toBeInstanceOf(InvoiceRef::class)
            ->and($this->dm->can('viewAny', InvoiceRef::class))->toBeTrue();
    });
});

describe('transitions', function () {
    it('allows every legal move and refuses every other', function (InvoiceStatus $from, InvoiceStatus $to, bool $legal) {
        expect($from->canTransitionTo($to))->toBe($legal);
    })->with(function () {
        foreach (InvoiceStatus::cases() as $from) {
            foreach (InvoiceStatus::cases() as $to) {
                $legal = in_array([$from, $to], [
                    [InvoiceStatus::Draft, InvoiceStatus::Sent], [InvoiceStatus::Sent, InvoiceStatus::Paid],
                    [InvoiceStatus::Sent, InvoiceStatus::Overdue], [InvoiceStatus::Overdue, InvoiceStatus::Paid],
                ], true);

                yield "{$from->value} to {$to->value}" => [$from, $to, $legal];
            }
        }
    });

    it('sends a draft that has a due date, filling the issue date', function () {
        $invoice = ($this->save)(['due_at' => now()->addDays(10)->toDateString()]);

        $sent = app(SendInvoiceRef::class)($invoice, $this->finance);

        expect($sent->status)->toBe(InvoiceStatus::Sent)->and($sent->issued_at->toDateString())->toBe(today()->toDateString());
    });

    it('will not send without a due date, or send twice', function () {
        $invoice = ($this->save)();

        expect(fn () => app(SendInvoiceRef::class)($invoice, $this->finance))->toThrow(ActionBlocked::class, 'due date');

        $invoice = ($this->save)(['zoho_invoice_ref' => 'INV-002', 'due_at' => now()->addDay()->toDateString()]);
        $invoice = app(SendInvoiceRef::class)($invoice, $this->finance);

        expect(fn () => app(SendInvoiceRef::class)($invoice, $this->finance))->toThrow(IllegalTransition::class);
    });

    it('pays a sent or overdue invoice, and a paid one is final', function () {
        foreach ([InvoiceStatus::Sent, InvoiceStatus::Overdue] as $i => $status) {
            $invoice = InvoiceRef::factory()->status($status)->create(['project_id' => $this->project->id]);

            expect(app(MarkInvoicePaid::class)($invoice, $this->finance)->status)->toBe(InvoiceStatus::Paid);
        }

        $paid = InvoiceRef::query()->where('status', 'paid')->first();
        expect(fn () => app(MarkInvoicePaid::class)($paid, $this->finance))->toThrow(IllegalTransition::class)
            ->and(fn () => app(MarkInvoiceOverdue::class)($paid, $this->finance))->toThrow(IllegalTransition::class);

        $draft = InvoiceRef::factory()->create(['project_id' => $this->project->id]);
        expect(fn () => app(MarkInvoicePaid::class)($draft, $this->finance))->toThrow(IllegalTransition::class);
    });

    it('marks overdue by hand only after the due date has passed', function () {
        $future = InvoiceRef::factory()->status(InvoiceStatus::Sent)->create(['project_id' => $this->project->id, 'due_at' => now()->addDay()->toDateString()]);
        $past = InvoiceRef::factory()->status(InvoiceStatus::Sent)->create(['project_id' => $this->project->id, 'due_at' => now()->subDay()->toDateString()]);

        expect(fn () => app(MarkInvoiceOverdue::class)($future, $this->finance))->toThrow(ActionBlocked::class, 'after its due date')
            ->and(app(MarkInvoiceOverdue::class)($past, $this->finance)->status)->toBe(InvoiceStatus::Overdue);
    });

    it('is finance only for every step', function () {
        $invoice = ($this->save)(['due_at' => now()->addDay()->toDateString()]);

        expect(fn () => app(SendInvoiceRef::class)($invoice, $this->dm))->toThrow(AuthorizationException::class)
            ->and(fn () => app(MarkInvoicePaid::class)($invoice, $this->dm))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeleteInvoiceRef::class)($invoice, $this->dm))->toThrow(AuthorizationException::class);
    });

    it('deletes a draft only', function () {
        $draft = ($this->save)();
        app(DeleteInvoiceRef::class)($draft, $this->finance);
        expect(InvoiceRef::query()->find($draft->id))->toBeNull();

        foreach ([InvoiceStatus::Sent, InvoiceStatus::Overdue, InvoiceStatus::Paid] as $status) {
            $kept = InvoiceRef::factory()->status($status)->create(['project_id' => $this->project->id]);

            expect(fn () => app(DeleteInvoiceRef::class)($kept, $this->finance))->toThrow(ActionBlocked::class);
            expect(InvoiceRef::query()->find($kept->id))->not->toBeNull();
        }
    });
});

describe('the daily overdue job', function () {
    it('moves sent invoices past due to overdue and leaves everything else alone', function () {
        $late = InvoiceRef::factory()->status(InvoiceStatus::Sent)->create(['project_id' => $this->project->id, 'due_at' => now()->subDays(2)->toDateString()]);
        $today = InvoiceRef::factory()->status(InvoiceStatus::Sent)->create(['project_id' => $this->project->id, 'due_at' => today()->toDateString()]);
        $future = InvoiceRef::factory()->status(InvoiceStatus::Sent)->create(['project_id' => $this->project->id, 'due_at' => now()->addDays(2)->toDateString()]);
        $draft = InvoiceRef::factory()->create(['project_id' => $this->project->id, 'due_at' => now()->subDays(5)->toDateString()]);
        $paid = InvoiceRef::factory()->status(InvoiceStatus::Paid)->create(['project_id' => $this->project->id, 'due_at' => now()->subDays(5)->toDateString()]);

        $this->artisan('groibe:mark-overdue-invoices')->assertSuccessful();

        expect($late->fresh()->status)->toBe(InvoiceStatus::Overdue)
            ->and($today->fresh()->status)->toBe(InvoiceStatus::Sent)
            ->and($future->fresh()->status)->toBe(InvoiceStatus::Sent)
            ->and($draft->fresh()->status)->toBe(InvoiceStatus::Draft)
            ->and($paid->fresh()->status)->toBe(InvoiceStatus::Paid);
    });

    it('tells finance once, with project and invoice number but no amount, and nobody else', function () {
        InvoiceRef::factory()->status(InvoiceStatus::Sent)->create(['project_id' => $this->project->id, 'zoho_invoice_ref' => 'INV-LATE', 'amount' => 98765, 'due_at' => now()->subDay()->toDateString()]);
        $super = adminUser(AdminRole::SuperAdmin);

        $this->artisan('groibe:mark-overdue-invoices')->assertSuccessful();

        Notification::assertSentTo([$this->finance, $super], InvoicesOverdue::class, function (InvoicesOverdue $n) use ($super) {
            $text = json_encode($n->toArray($super)).$n->toMail($super)->render();

            return str_contains($text, 'INV-LATE') && ! str_contains($text, '98765') && ! str_contains($text, '98,765');
        });
        Notification::assertNotSentTo([$this->dm, adminUser(AdminRole::CommunityManager)], InvoicesOverdue::class);

        // A second run the same day finds nothing new and sends nothing more.
        Notification::fake();
        $this->artisan('groibe:mark-overdue-invoices')->assertSuccessful();
        Notification::assertNothingSent();
    });

    it('is scheduled daily', function () {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'groibe:mark-overdue-invoices'));

        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('15 8 * * *');
    });
});

describe('the Invoice References screen', function () {
    it('adds a reference and warns when invoices pass the contract value', function () {
        Livewire\Livewire::actingAs($this->finance)->test(ListInvoiceRefs::class)
            ->callAction('create', ['project_id' => $this->project->id, 'zoho_invoice_ref' => 'INV-900', 'amount' => '150000'])
            ->assertNotified('Invoices now exceed the contract value');

        expect(InvoiceRef::query()->where('zoho_invoice_ref', 'INV-900')->exists())->toBeTrue();
    });

    it('runs the steps from the screen', function () {
        $invoice = ($this->save)(['due_at' => now()->addDays(5)->toDateString()]);

        $page = Livewire\Livewire::actingAs($this->finance)->test(ListInvoiceRefs::class);

        $page->callTableAction('send', $invoice)->assertNotified('Invoice marked sent');
        $page->callTableAction('paid', $invoice)->assertNotified('Invoice marked paid');

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    });
});
