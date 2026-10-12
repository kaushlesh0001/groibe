<?php

use App\Actions\Privacy\OpenDataRequest;
use App\Enums\AdminRole;
use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Enums\OnboardingState;
use App\Enums\UserStatus;
use App\Filament\Admin\Resources\DataRequests\DataRequestResource;
use App\Filament\Admin\Resources\DataRequests\Pages\ListDataRequests;
use App\Livewire\Settings\Privacy;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\DataRequest;
use App\Models\Payout;
use App\Models\User;
use App\Notifications\DataRequestUpdated;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

require_once __DIR__.'/RichContributor.php';

describe('the admin screen', function () {
    it('opens for a super admin only', function () {
        $this->actingAs(adminUser())->get(DataRequestResource::getUrl('index'))->assertOk();

        foreach ([AdminRole::DeliveryManager, AdminRole::CommunityManager, AdminRole::FinanceOps] as $role) {
            asUser(adminUser($role))->get(DataRequestResource::getUrl('index'))->assertForbidden();
        }

        asUser(contributorUser())->get(DataRequestResource::getUrl('index'))->assertForbidden();
        asUser(clientUser())->get(DataRequestResource::getUrl('index'))->assertForbidden();

        test()->flushSession();
        auth()->forgetGuards();
        $this->get(DataRequestResource::getUrl('index'))->assertRedirect();
    });

    it('lists the waiting requests first, and the rest on a filter', function () {
        $open = DataRequest::factory()->create();
        $taken = DataRequest::factory()->status(DataRequestStatus::InProgress)->create();
        $done = DataRequest::factory()->status(DataRequestStatus::Completed)->create();

        Livewire::actingAs(adminUser())->test(ListDataRequests::class)
            ->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$taken, $done])
            ->filterTable('status', DataRequestStatus::InProgress->value)->assertCanSeeTableRecords([$taken])
            ->filterTable('status', DataRequestStatus::Completed->value)->assertCanSeeTableRecords([$done]);
    });

    it('offers only the actions that fit the request', function (DataRequestType $type, DataRequestStatus $status, array $visible) {
        $request = DataRequest::factory()->status($status)->create(['type' => $type]);
        $page = Livewire::actingAs(adminUser())->test(ListDataRequests::class)->removeTableFilters();

        foreach (['start', 'download', 'completeExport', 'completeDeletion', 'reject'] as $action) {
            in_array($action, $visible, true)
                ? $page->assertTableActionVisible($action, $request)
                : $page->assertTableActionHidden($action, $request);
        }
    })->with([
        'export, open' => [DataRequestType::Export, DataRequestStatus::Open, ['start', 'reject']],
        'export, in progress' => [DataRequestType::Export, DataRequestStatus::InProgress, ['download', 'completeExport', 'reject']],
        'deletion, open' => [DataRequestType::Deletion, DataRequestStatus::Open, ['start', 'reject']],
        'deletion, in progress' => [DataRequestType::Deletion, DataRequestStatus::InProgress, ['completeDeletion', 'reject']],
        'export, completed' => [DataRequestType::Export, DataRequestStatus::Completed, []],
        'deletion, rejected' => [DataRequestType::Deletion, DataRequestStatus::Rejected, []],
    ]);

    it('takes an export request, hands over the data as a JSON download, and completes it with a note', function () {
        Notification::fake();
        ['person' => $person] = richContributor();
        $request = DataRequest::factory()->create(['user_id' => $person->id]);
        $page = Livewire::actingAs(adminUser())->test(ListDataRequests::class)->removeTableFilters();

        $page->callTableAction('start', $request);
        expect($request->refresh()->status)->toBe(DataRequestStatus::InProgress);

        $page->callTableAction('download', $request)->assertFileDownloaded("data-export-{$request->uuid}.json");

        $page->callTableAction('completeExport', $request, data: ['note' => 'Emailed as an attachment.']);
        expect($request->refresh()->status)->toBe(DataRequestStatus::Completed)->and($request->note)->toBe('Emailed as an attachment.');

        Notification::assertSentTo($person, DataRequestUpdated::class, fn (DataRequestUpdated $n) => $n->event === 'completed');
    });

    it('rejects a request with a reason the person is told', function () {
        Notification::fake();
        $request = DataRequest::factory()->create();

        Livewire::actingAs(adminUser())->test(ListDataRequests::class)->removeTableFilters()
            ->callTableAction('reject', $request, data: ['reason' => 'We could not confirm it was you.'])
            ->assertHasNoTableActionErrors();

        expect($request->refresh()->status)->toBe(DataRequestStatus::Rejected)->and($request->note)->toBe('We could not confirm it was you.');
        Notification::assertSentTo($request->user, DataRequestUpdated::class, fn (DataRequestUpdated $n) => $n->event === 'rejected');
    });

    it('needs a reason and a note where the action asks for one', function () {
        $request = DataRequest::factory()->status(DataRequestStatus::InProgress)->create();
        $page = Livewire::actingAs(adminUser())->test(ListDataRequests::class)->removeTableFilters();

        $page->callTableAction('reject', $request, data: ['reason' => ''])->assertHasTableActionErrors(['reason' => 'required']);

        Livewire::actingAs(adminUser())->test(ListDataRequests::class)->removeTableFilters()
            ->callTableAction('completeExport', $request, data: ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);

        expect($request->refresh()->status)->toBe(DataRequestStatus::InProgress);
    });

    it('erases the person from an erasure request, and explains when it cannot', function () {
        ['person' => $person] = richContributor();
        $request = DataRequest::factory()->deletion()->status(DataRequestStatus::InProgress)->create(['user_id' => $person->id]);
        $page = Livewire::actingAs(adminUser())->test(ListDataRequests::class)->removeTableFilters();

        // A planned payout is still waiting, so the first attempt is refused with the reason, and nothing changes.
        $page->callTableAction('completeDeletion', $request, data: ['note' => ''])->assertNotified('That did not go through');
        expect($request->refresh()->status)->toBe(DataRequestStatus::InProgress)->and($person->refresh()->trashed())->toBeFalse();

        Payout::query()->where('user_id', $person->id)->where('status', 'planned')->delete();

        $page->callTableAction('completeDeletion', $request, data: ['note' => 'Done.'])->assertNotified('Personal data erased and account closed');
        expect($request->refresh()->status)->toBe(DataRequestStatus::Completed)->and(User::withTrashed()->find($person->id)->name)->toBe('Deleted user');
    });

    it('shows an erased person as "Deleted user" on the list, not an error', function () {
        $request = DataRequest::factory()->deletion()->status(DataRequestStatus::InProgress)->create();
        Livewire::actingAs(adminUser())->test(ListDataRequests::class)->removeTableFilters()
            ->callTableAction('completeDeletion', $request, data: ['note' => '']);

        Livewire::actingAs(adminUser())->test(ListDataRequests::class)->removeTableFilters()
            ->filterTable('status', DataRequestStatus::Completed->value)->assertSee('Deleted user');
    });

    it('does not let a role without the right run an action by hand', function () {
        $request = DataRequest::factory()->create();

        Livewire::actingAs(adminUser(AdminRole::DeliveryManager))->test(ListDataRequests::class)->assertForbidden();

        expect($request->refresh()->status)->toBe(DataRequestStatus::Open);
    });
});

describe('the page for contributors and clients', function () {
    it('opens for a contributor and a client, and answers an admin and a guest with a refusal', function () {
        $this->actingAs(contributorIn(OnboardingState::Active))->get(route('settings.privacy'))->assertOk()->assertSee('Privacy and your data');
        asUser(contributorIn(OnboardingState::PendingReview))->get(route('settings.privacy'))->assertOk();
        asUser(clientUser())->get(route('settings.privacy'))->assertOk();
        asUser(adminUser())->get(route('settings.privacy'))->assertForbidden();

        test()->flushSession();
        auth()->forgetGuards();
        $this->get(route('settings.privacy'))->assertRedirect();
    });

    it('opens for a suspended contributor, who keeps the right', function () {
        $user = contributorIn(OnboardingState::Suspended);
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->actingAs($user)->get(route('settings.privacy'))->assertOk();
        Notification::fake();
        Livewire::actingAs($user)->test(Privacy::class)->call('requestDeletion')->assertSee('request to erase your data was sent');
    });

    it('sends a request for a copy and one for erasure, and says so', function () {
        Notification::fake();
        $user = contributorIn(OnboardingState::Active);

        Livewire::actingAs($user)->test(Privacy::class)
            ->call('requestExport')->assertSee('request for a copy of your data was sent')->assertSee('Copy of my data')
            ->call('requestDeletion')->assertSee('request to erase your data was sent')->assertSee('Erase my data');

        expect(DataRequest::query()->where('user_id', $user->id)->pluck('type')->map(fn ($type) => $type->value)->sort()->values()->all())->toBe(['deletion', 'export']);
    });

    it('says plainly when one is already waiting, and does not make a second', function () {
        Notification::fake();
        $user = contributorIn(OnboardingState::Active);

        Livewire::actingAs($user)->test(Privacy::class)->call('requestExport')->call('requestExport')->assertSee('already have a request');

        expect(DataRequest::query()->where('user_id', $user->id)->count())->toBe(1);
    });

    it('limits how fast requests can be sent', function () {
        Notification::fake();
        $page = Livewire::actingAs(contributorIn(OnboardingState::Active))->test(Privacy::class);

        foreach (range(1, 6) as $_) {
            $page->call('requestExport');
        }

        $page->call('requestExport')->assertSee('Too many attempts');
    });

    it('shows only the person\'s own requests, with the message Groibe left', function () {
        $user = contributorIn(OnboardingState::Active);
        DataRequest::factory()->status(DataRequestStatus::Completed)->create(['user_id' => $user->id, 'note' => 'Emailed to you on Monday.']);
        $theirs = DataRequest::factory()->status(DataRequestStatus::Rejected)->create(['note' => 'SOMEONE ELSES REASON']);

        Livewire::actingAs($user)->test(Privacy::class)
            ->assertSee('Emailed to you on Monday.')->assertSee('Completed')
            ->assertDontSee('SOMEONE ELSES REASON')->assertDontSee($theirs->user->email);
    });

    it('lists the agreements the person accepted with the date, and only theirs', function () {
        $user = contributorIn(OnboardingState::Active);
        $template = AgreementTemplate::factory()->create(['title' => 'Member Agreement', 'version' => 4, 'is_active' => true]);
        AgreementAcceptance::query()->create(['template_id' => $template->id, 'user_id' => $user->id, 'accepted_at' => now()->subDays(3)]);
        AgreementAcceptance::query()->create(['template_id' => AgreementTemplate::factory()->create(['title' => 'Client MSA', 'version' => 9])->id, 'user_id' => clientUser()->id, 'accepted_at' => now()]);

        Livewire::actingAs($user)->test(Privacy::class)
            ->assertSee('Member Agreement (version 4)')->assertSee(now()->subDays(3)->format('Y'))->assertDontSee('Client MSA');
    });

    it('is linked from the settings menu for contributors and clients', function () {
        $this->actingAs(contributorIn(OnboardingState::Active))->get(route('settings.profile'))->assertSee(route('settings.privacy'));
        asUser(clientUser())->get(route('settings.profile'))->assertSee(route('settings.privacy'));
    });

    it('tells the admins when a person asks', function () {
        Notification::fake();
        $super = adminUser();

        Livewire::actingAs(contributorIn(OnboardingState::Active))->test(Privacy::class)->call('requestExport');

        Notification::assertSentTo($super, DataRequestUpdated::class);
        expect(app(OpenDataRequest::class))->not->toBeNull();
    });
});
