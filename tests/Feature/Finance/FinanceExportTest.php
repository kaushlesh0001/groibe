<?php

use App\Enums\AdminRole;
use App\Enums\InvoiceStatus;
use App\Enums\PayoutStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Filament\Admin\Resources\InvoiceRefs\Pages\ListInvoiceRefs;
use App\Filament\Admin\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Admin\Resources\Payouts\PayoutResource;
use App\Models\Assignment;
use App\Models\InvoiceRef;
use App\Models\Payout;
use App\Models\Project;
use App\Services\Exports\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $this->finance = adminUser(AdminRole::FinanceOps);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['code' => 'GRB-2026-0042', 'title' => 'Pricing study']);
    $this->seat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'agreed_amount' => 90000]);
    $this->seat->user->update(['name' => 'Meera Nair', 'email' => 'meera.private@example.test']);
});

/** Runs a streamed download and returns its CSV rows, without the UTF-8 byte order mark every export starts with (SCR-025). */
function csvRows(StreamedResponse $response): array
{
    ob_start();
    $response->sendContent();

    return array_map('str_getcsv', array_filter(explode("\n", ltrim((string) ob_get_clean(), "\xEF\xBB\xBF"))));
}

it('exports the agreed payout columns and nothing about contact details or notes', function () {
    Payout::factory()->status(PayoutStatus::Paid)->create([
        'assignment_id' => $this->seat->id, 'amount' => 25000, 'tds_amount' => 2500, 'reference' => 'UTR123456789', 'notes' => 'Internal haggling note',
    ]);

    $rows = csvRows(app(CsvExport::class)->download(
        Payout::query()->with(['project', 'user', 'milestone']),
        'payouts',
        ['Payout ID', 'Project code', 'Contributor', 'Amount', 'TDS', 'Net', 'Status', 'UTR reference'],
        fn (Payout $p) => [$p->id, $p->project->code, $p->user->name, $p->amount, $p->tds_amount, $p->net(), $p->status->value, $p->reference],
        $this->finance,
        'payout_export',
    ));

    expect($rows[0])->toBe(['Payout ID', 'Project code', 'Contributor', 'Amount', 'TDS', 'Net', 'Status', 'UTR reference'])
        ->and($rows[1])->toBe(['1', 'GRB-2026-0042', 'Meera Nair', '25000.00', '2500.00', '22500.00', 'paid', 'UTR123456789']);
});

it('exports payouts from the screen as a CSV with the real column set, for finance only', function () {
    Payout::factory()->status(PayoutStatus::Approved)->create(['assignment_id' => $this->seat->id, 'amount' => 1000, 'notes' => 'Internal haggling note']);

    $page = Livewire::actingAs($this->finance)->test(ListPayouts::class)->callAction('export');
    $page->assertFileDownloaded();

    $response = app(CsvExport::class)->download(
        Payout::query(), 'payouts', ['x'], fn () => ['x'], $this->finance, 'payout_export',
    );
    expect($response->headers->get('Content-Type'))->toContain('text/csv')->and($response->headers->get('Cache-Control'))->toContain('no-store');

    // A delivery manager can read payouts but not export them. A community manager cannot even open the page.
    Livewire::actingAs(adminUser(AdminRole::DeliveryManager))->test(ListPayouts::class)->assertActionHidden('export');
    $this->actingAs(adminUser(AdminRole::CommunityManager))->get(PayoutResource::getUrl('index'))->assertForbidden();
});

it('leaves contributor email, phone and internal notes out of the screen export row', function () {
    $payout = Payout::factory()->status(PayoutStatus::Approved)->create(['assignment_id' => $this->seat->id, 'amount' => 1000, 'notes' => 'Internal haggling note']);
    $payout->load(['project', 'user', 'milestone']);

    $page = Livewire::actingAs($this->finance)->test(ListPayouts::class)->instance();
    $row = (new ReflectionMethod($page, 'exportRow'))->invoke($page, $payout);

    expect($row)->toHaveCount(13)
        ->and(implode('|', array_map('strval', $row)))->toContain('Meera Nair')->toContain('GRB-2026-0042')
        ->not->toContain('Internal haggling note')->not->toContain('meera.private@example.test');
});

it('neutralises cells a spreadsheet would run as a formula', function (string $value, string $expected) {
    expect(app(CsvExport::class)->safe($value))->toBe($expected);
})->with([
    ['=SUM(A1)', "'=SUM(A1)"],
    ['+91 98765', "'+91 98765"],
    ['-2+3', "'-2+3"],
    ['@cmd', "'@cmd"],
    ["\tcmd", "'\tcmd"],
    ['Meera Nair', 'Meera Nair'],
    ['25000.00', '25000.00'],
    ['', ''],
]);

it('protects a formula-looking contributor name or project title in the file', function () {
    $this->seat->user->update(['name' => '=HYPERLINK("http://evil.example")']);
    Payout::factory()->status(PayoutStatus::Approved)->create(['assignment_id' => $this->seat->id, 'amount' => 10]);

    $rows = csvRows(app(CsvExport::class)->download(
        Payout::query()->with('user'), 'payouts', ['Contributor'], fn (Payout $p) => [$p->user->name], $this->finance, 'payout_export',
    ));

    expect($rows[1][0])->toStartWith("'=");
});

it('respects the filters on screen', function () {
    Payout::factory()->status(PayoutStatus::Approved)->create(['assignment_id' => $this->seat->id, 'amount' => 111]);
    Payout::factory()->status(PayoutStatus::Cancelled)->create(['assignment_id' => $this->seat->id, 'amount' => 222]);

    $page = Livewire::actingAs($this->finance)->test(ListPayouts::class)->filterTable('status', 'approved');

    $query = $page->instance()->getFilteredTableQuery();
    expect($query->pluck('amount')->map(fn ($a) => (string) $a)->all())->toBe(['111.00']);
});

it('writes every export to the audit log with the row count, never the rows', function () {
    Payout::factory()->count(3)->status(PayoutStatus::Approved)->create(['assignment_id' => $this->seat->id, 'amount' => 1]);

    csvRows(app(CsvExport::class)->download(Payout::query(), 'payouts', ['a'], fn () => ['a'], $this->finance, 'payout_export'));

    $entry = Activity::query()->where('log_name', 'payout_export')->sole();

    expect($entry->causer_id)->toBe($this->finance->id)->and($entry->event)->toBe('exported')->and($entry->properties['rows'])->toBe(3);
});

it('refuses an export above 5,000 rows and says how to narrow it', function () {
    $query = Mockery::mock(Builder::class);
    $query->shouldReceive('count')->andReturn(5001);

    expect(fn () => app(CsvExport::class)->download($query, 'payouts', ['a'], fn () => ['a'], $this->finance, 'payout_export'))
        ->toThrow(ActionBlocked::class, 'Narrow the filters');

    expect(CsvExport::MAX_ROWS)->toBe(5000)->and(Activity::query()->where('log_name', 'payout_export')->exists())->toBeFalse();
});

it('exports invoice references for finance and not for delivery managers', function () {
    InvoiceRef::factory()->status(InvoiceStatus::Sent)->create(['project_id' => $this->project->id, 'zoho_invoice_ref' => 'INV-777', 'amount' => 5000]);

    Livewire::actingAs($this->finance)->test(ListInvoiceRefs::class)->callAction('export')->assertFileDownloaded();
    Livewire::actingAs(adminUser(AdminRole::DeliveryManager))->test(ListInvoiceRefs::class)->assertActionHidden('export');

    expect(Activity::query()->where('log_name', 'invoice_export')->count())->toBe(1);
});
