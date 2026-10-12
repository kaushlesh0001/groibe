<?php

use App\Actions\Messages\GetProjectThread;
use App\Actions\Messages\StartSupportThread;
use App\Enums\AdminRole;
use App\Enums\FeatureFlag;
use App\Enums\InvoiceStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\PayoutStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserStatus;
use App\Filament\Admin\Resources\InvoiceRefs\Pages\ListInvoiceRefs;
use App\Filament\Admin\Resources\Payouts\Pages\ListPayouts;
use App\Livewire\Shared\Messages\Conversation;
use App\Livewire\Shared\Messages\Inbox;
use App\Models\Assignment;
use App\Models\InvoiceRef;
use App\Models\Message;
use App\Models\Milestone;
use App\Models\Payout;
use App\Models\Project;
use App\Models\Thread;
use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Review Prompt, Pass 7: denied and user-interface paths that the action-level tests did not exercise through the real
 * components and screens.
 */
beforeEach(function () {
    Storage::fake('local');
    Notification::fake();

    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->finance = adminUser(AdminRole::FinanceOps);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $this->dm->id]);
    $this->owner = clientUser($this->project->organization, OrgRole::Owner);
    $this->viewer = clientUser($this->project->organization, OrgRole::Viewer);
    $this->me = contributorIn(OnboardingState::Active);
    $this->seat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->me->id, 'agreed_amount' => 900000]);
    $this->thread = app(GetProjectThread::class)->ensure($this->project, $this->dm);
});

describe('the conversation page', function () {
    it('lets a client and a contributor post through the page, with a file', function () {
        Livewire::actingAs($this->owner)->test(Conversation::class, ['thread' => $this->thread])
            ->set('body', 'Hello from the client')->set('uploads', [UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf')])->call('send')
            ->assertHasNoErrors()->assertSee('Hello from the client')->assertSee('brief.pdf');

        Livewire::actingAs($this->me)->test(Conversation::class, ['thread' => $this->thread])
            ->set('body', 'Hello from the contributor')->call('send')->assertHasNoErrors()->assertSee('Hello from the client');

        expect(Message::query()->where('thread_id', $this->thread->id)->count())->toBe(2);
    });

    it('refuses a post on a closed project and shows the conversation as read-only', function () {
        $this->project->update(['status' => ProjectStatus::Closed]);

        Livewire::actingAs($this->owner)->test(Conversation::class, ['thread' => $this->thread])
            ->assertSee('read-only')->set('body', 'Too late')->call('send')->assertHasErrors('body');

        expect(Message::query()->where('body', 'Too late')->exists())->toBeFalse();
    });

    it('does not let the browser raise the page size, only the Show earlier button does', function () {
        $component = Livewire::actingAs($this->owner)->test(Conversation::class, ['thread' => $this->thread]);

        expect(fn () => $component->set('limit', 1000000))->toThrow(Exception::class);

        $component->call('showEarlier')->assertOk();
        expect($component->get('limit'))->toBe(100);
    });

    it('shows a plain message for an empty post and an over-long one', function () {
        Livewire::actingAs($this->owner)->test(Conversation::class, ['thread' => $this->thread])
            ->set('body', '   ')->call('send')->assertHasErrors('body')
            ->set('body', str_repeat('a', 5001))->call('send')->assertHasErrors('body');
    });

    it('turns a disallowed file into a message on the page, not an error page', function () {
        Livewire::actingAs($this->owner)->test(Conversation::class, ['thread' => $this->thread])
            ->set('body', 'With a script')->set('uploads', [UploadedFile::fake()->create('run.php', 1)])->call('send')->assertHasErrors('uploads');

        expect(Message::query()->where('body', 'With a script')->exists())->toBeFalse();
    });

    it('stops answering once the account is suspended while the page is open', function () {
        $component = Livewire::actingAs($this->me)->test(Conversation::class, ['thread' => $this->thread])->assertOk();

        $this->me->forceFill(['status' => UserStatus::Suspended])->save();

        $component->call('$refresh')->assertNotFound();
    });

    it('stops answering once messaging is switched off while the page is open', function () {
        $component = Livewire::actingAs($this->owner)->test(Conversation::class, ['thread' => $this->thread])->assertOk();

        app(FeatureFlags::class)->set(FeatureFlag::Messaging, false);

        $component->call('$refresh')->assertNotFound();
    });

    it('does not let a client viewer open or post to the project conversation', function () {
        Livewire::actingAs($this->viewer)->test(Conversation::class, ['thread' => $this->thread])->assertNotFound();
    });
});

describe('the messages inbox', function () {
    it('starts a support thread from the page for a contributor and for a client', function () {
        foreach ([$this->me, $this->viewer] as $person) {
            Livewire::actingAs($person)->test(Inbox::class)
                ->set('composing', true)->set('subject', 'Need some help')->set('body', 'Could you look at this?')->call('start')
                ->assertHasNoErrors()->assertRedirect();
        }

        expect(Thread::query()->where('subject', 'Need some help')->count())->toBe(2);
    });

    it('explains a missing subject or message instead of failing', function () {
        Livewire::actingAs($this->me)->test(Inbox::class)
            ->set('subject', 'ab')->set('body', 'Hello')->call('start')->assertHasErrors('subject')
            ->set('subject', 'A real subject')->set('body', '  ')->call('start')->assertHasErrors('body');

        expect(Thread::query()->where('type', 'support')->count())->toBe(0);
    });

    it('does not offer an admin the support composer', function () {
        $community = adminUser(AdminRole::CommunityManager);

        Livewire::actingAs($community)->test(Inbox::class)->assertViewHas('canStartSupport', false);
    });
});

describe('the finance screens', function () {
    it('edits and deletes a planned payout from the screen, and not an approved one', function () {
        $planned = Payout::factory()->status(PayoutStatus::Planned)->create(['assignment_id' => $this->seat->id, 'amount' => 1000]);
        $approved = Payout::factory()->status(PayoutStatus::Approved)->create(['assignment_id' => $this->seat->id, 'amount' => 2000]);
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);

        $page = Livewire::actingAs($this->finance)->test(ListPayouts::class);

        $page->callTableAction('edit', $planned, ['amount' => '1500', 'milestone_id' => $milestone->id, 'notes' => 'Adjusted'])->assertNotified('Payout updated');
        expect((string) $planned->fresh()->amount)->toBe('1500.00')->and($planned->fresh()->milestone_id)->toBe($milestone->id);

        $page->callTableAction('delete', $planned)->assertNotified('Payout deleted');
        expect(Payout::query()->find($planned->id))->toBeNull();

        $page->assertTableActionHidden('delete', $approved)->assertTableActionHidden('edit', $approved);
        expect(Payout::query()->find($approved->id))->not->toBeNull();
    });

    it('explains a refusal on the screen, for example a payout above the agreed amount', function () {
        $payout = Payout::factory()->status(PayoutStatus::Planned)->create(['assignment_id' => $this->seat->id, 'amount' => 1000]);

        Livewire::actingAs($this->finance)->test(ListPayouts::class)
            ->callTableAction('edit', $payout, ['amount' => '99999999'])->assertNotified('That did not go through');

        expect((string) $payout->fresh()->amount)->toBe('1000.00');
    });

    it('edits and deletes a draft invoice reference from the screen, and refuses a duplicate number', function () {
        $draft = InvoiceRef::factory()->create(['project_id' => $this->project->id, 'zoho_invoice_ref' => 'INV-100', 'amount' => 1000]);
        InvoiceRef::factory()->create(['project_id' => $this->project->id, 'zoho_invoice_ref' => 'INV-200']);

        $page = Livewire::actingAs($this->finance)->test(ListInvoiceRefs::class);

        $page->callTableAction('edit', $draft, ['zoho_invoice_ref' => 'INV-200', 'amount' => '1000'])->assertNotified('That did not go through');
        expect($draft->fresh()->zoho_invoice_ref)->toBe('INV-100');

        $page->callTableAction('edit', $draft, ['zoho_invoice_ref' => 'INV-101', 'amount' => '2500'])->assertNotified('Invoice reference updated');
        expect($draft->fresh()->zoho_invoice_ref)->toBe('INV-101');

        $page->callTableAction('delete', $draft)->assertNotified('Invoice reference deleted');
        expect(InvoiceRef::query()->find($draft->id))->toBeNull();
    });

    it('cannot be driven by a delivery manager even by calling a hidden action directly', function () {
        $planned = Payout::factory()->status(PayoutStatus::Planned)->create(['assignment_id' => $this->seat->id, 'amount' => 1000]);
        $invoice = InvoiceRef::factory()->status(InvoiceStatus::Sent)->create(['project_id' => $this->project->id]);

        $payouts = Livewire::actingAs($this->dm)->test(ListPayouts::class);
        $invoices = Livewire::actingAs($this->dm)->test(ListInvoiceRefs::class);

        foreach (['approve', 'cancel', 'delete'] as $action) {
            try {
                $payouts->callTableAction($action, $planned);
            } catch (Throwable) {
                // Filament refuses a hidden action; what matters is that nothing changed.
            }
        }

        try {
            $invoices->callTableAction('paid', $invoice);
        } catch (Throwable) {
        }

        expect($planned->fresh()->status)->toBe(PayoutStatus::Planned)
            ->and(Payout::query()->find($planned->id))->not->toBeNull()
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Sent);
    });
});

it('keeps support attachments out of the project conversation list a delivery manager sees', function () {
    $support = app(StartSupportThread::class)($this->me, 'Private matter', 'See the attachment', [UploadedFile::fake()->create('id.pdf', 5, 'application/pdf')]);

    $visible = Thread::query()->visibleTo($this->dm)->pluck('id')->all();

    expect($visible)->not->toContain($support->id)->toContain($this->thread->id);
});
