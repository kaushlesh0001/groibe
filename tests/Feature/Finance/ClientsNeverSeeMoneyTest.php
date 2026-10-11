<?php

use App\Actions\Messages\GetProjectThread;
use App\Actions\Payouts\ApprovePayout;
use App\Actions\Payouts\MarkPayoutPaid;
use App\Enums\AdminRole;
use App\Enums\InvoiceStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\PayoutStatus;
use App\Enums\ProjectStatus;
use App\Models\Assignment;
use App\Models\InvoiceRef;
use App\Models\Payout;
use App\Models\Project;
use App\Notifications\InvoicesOverdue;
use App\Notifications\PayoutStatusChanged;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/*
 * Distinctive figures are planted on the client's own project, then every client-facing page and route is checked for them.
 * Clients never see contributor rates, payouts or finance references (spec 12).
 */
beforeEach(function () {
    $this->finance = adminUser(AdminRole::FinanceOps);
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $this->dm->id, 'title' => 'Client visible project']);

    $this->owner = clientUser($this->project->organization, OrgRole::Owner);
    $this->manager = clientUser($this->project->organization, OrgRole::Manager);
    $this->viewer = clientUser($this->project->organization, OrgRole::Viewer);

    $this->contributor = contributorIn(OnboardingState::Active, [], ['name' => 'Priya Menon']);
    $this->seat = Assignment::factory()->active()->create([
        'project_id' => $this->project->id, 'user_id' => $this->contributor->id, 'agreed_amount' => 987654, 'role_title' => 'Analyst',
    ]);

    $this->payout = Payout::factory()->status(PayoutStatus::Paid)->create([
        'assignment_id' => $this->seat->id, 'amount' => 123456, 'tds_amount' => 12345, 'reference' => 'UTRZZ99887766', 'notes' => 'Haggled hard',
    ]);
    $this->invoice = InvoiceRef::factory()->status(InvoiceStatus::Sent)->create([
        'project_id' => $this->project->id, 'zoho_invoice_ref' => 'ZINV-SECRET-77', 'amount' => 765432,
    ]);

    $this->secrets = ['123456', '123,456', '12345', '987654', '987,654', '765432', '765,432', 'UTRZZ99887766', 'ZINV-SECRET-77', 'Haggled hard'];
});

it('shows no payout, rate or invoice figure on any client page', function (string $who, string $route) {
    $page = $this->actingAs($this->{$who})->get(route($route, $route === 'client.projects.show' ? $this->project : []));

    $page->assertOk();

    foreach ($this->secrets as $secret) {
        $page->assertDontSee($secret);
    }
})->with(function () {
    foreach (['owner', 'manager', 'viewer'] as $who) {
        foreach (['client.dashboard', 'client.projects.index', 'client.projects.show', 'client.deliverables', 'client.briefs.index', 'client.messages.index', 'notifications.index'] as $route) {
            yield "{$who} on {$route}" => [$who, $route];
        }
    }
});

it('refuses every client request to a payout or invoice route', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'payout') || str_contains($route->uri(), 'invoice-ref') || str_contains($route->uri(), 'invoice_ref'))
        ->filter(fn ($route) => in_array('GET', $route->methods(), true));

    // The contributor page, the two admin screens and the contributor navigation target must all exist, or this guard is empty.
    expect($routes->count())->toBeGreaterThanOrEqual(3);

    foreach ($routes as $route) {
        foreach ([$this->owner, $this->manager, $this->viewer] as $client) {
            $status = $this->actingAs($client)->get('/'.ltrim($route->uri(), '/'))->getStatusCode();

            expect($status)->not->toBe(200, "{$route->uri()} answered a client with 200");
        }
    }
});

it('never tells a client about a payout or an overdue invoice', function () {
    Notification::fake();

    $approved = Payout::factory()->status(PayoutStatus::Planned)->create(['assignment_id' => $this->seat->id, 'amount' => 1]);
    $approved = app(ApprovePayout::class)($approved, $this->finance);
    app(MarkPayoutPaid::class)($approved, $this->finance, 'UTR123456', today());

    $this->invoice->forceFill(['due_at' => now()->subDay()->toDateString()])->save();
    $this->artisan('groibe:mark-overdue-invoices')->assertSuccessful();

    Notification::assertSentTo($this->contributor, PayoutStatusChanged::class);
    Notification::assertSentTo($this->finance, InvoicesOverdue::class);
    Notification::assertNotSentTo([$this->owner, $this->manager, $this->viewer], PayoutStatusChanged::class);
    Notification::assertNotSentTo([$this->owner, $this->manager, $this->viewer], InvoicesOverdue::class);
});

it('keeps payout wording out of the client project conversation', function () {
    app(Container::class)->make(GetProjectThread::class)->ensure($this->project, $this->dm);

    $this->actingAs($this->owner)->get(route('client.messages.project', $this->project))->assertRedirect();

    foreach ($this->secrets as $secret) {
        $this->actingAs($this->owner)->get(route('client.messages.index'))->assertDontSee($secret);
    }
});

it('gives clients no way to reach the finance abilities through any policy', function () {
    foreach ([$this->owner, $this->manager, $this->viewer] as $client) {
        foreach (['viewAny', 'create', 'export'] as $ability) {
            expect($client->can($ability, Payout::class))->toBeFalse()
                ->and($client->can($ability, InvoiceRef::class))->toBeFalse();
        }

        expect($client->can('view', $this->payout))->toBeFalse()
            ->and($client->can('view', $this->invoice))->toBeFalse()
            ->and($client->can('setAmount', $this->seat))->toBeFalse();
    }
});

it('does not even deliver payout notifications to a client who somehow holds the contributor email address', function () {
    Notification::fake();

    // The dispatcher sends to exactly who it is given. Payout actions only ever give it the seat's own contributor.
    $approved = app(ApprovePayout::class)(Payout::factory()->create(['assignment_id' => $this->seat->id, 'amount' => 1]), $this->finance);

    Notification::assertSentTo($this->contributor, PayoutStatusChanged::class, 1);
    Notification::assertNotSentTo($this->owner, PayoutStatusChanged::class);
    expect($approved->user_id)->toBe($this->contributor->id)
        ->and(app(NotificationDispatcher::class))->toBeInstanceOf(NotificationDispatcher::class);
});
