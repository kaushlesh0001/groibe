<?php

use App\Actions\Messages\GetProjectThread;
use App\Actions\Messages\PostMessage;
use App\Actions\Messages\StartSupportThread;
use App\Enums\AdminRole;
use App\Enums\OnboardingState;
use App\Enums\ProjectStatus;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\RelationManagers\ThreadsRelationManager;
use App\Filament\Admin\Resources\Threads\Pages\ListThreads;
use App\Filament\Admin\Resources\Threads\ThreadResource;
use App\Livewire\Shared\Messages\Conversation;
use App\Models\Project;
use App\Models\Thread;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();

    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->community = adminUser(AdminRole::CommunityManager);
    $this->finance = adminUser(AdminRole::FinanceOps);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $this->dm->id]);
    $this->projectThread = app(GetProjectThread::class)->ensure($this->project, $this->dm);
    $this->contributor = contributorIn(OnboardingState::Active);
    $this->support = app(StartSupportThread::class)($this->contributor, 'Where is my payout', 'Please check.');
});

it('lists project conversations for delivery managers and support threads for community managers, each only their own', function () {
    Livewire::actingAs($this->dm)->test(ListThreads::class)
        ->assertCanSeeTableRecords([$this->projectThread])->assertCanNotSeeTableRecords([$this->support]);

    Livewire::actingAs($this->community)->test(ListThreads::class)
        ->assertCanSeeTableRecords([$this->support])->assertCanNotSeeTableRecords([$this->projectThread]);

    Livewire::actingAs(adminUser(AdminRole::SuperAdmin))->test(ListThreads::class)
        ->assertCanSeeTableRecords([$this->projectThread, $this->support]);
});

it('keeps finance away from conversations', function () {
    $this->actingAs($this->finance)->get(ThreadResource::getUrl('index'))->assertForbidden();
});

it('opens a conversation page for the admin who may see that thread', function (string $admin, string $thread) {
    $this->actingAs($this->{$admin})->get(ThreadResource::getUrl('view', ['record' => $this->{$thread}]))
        ->assertOk()->assertSee($this->{$thread}->subject);
})->with([['dm', 'projectThread'], ['community', 'support']]);

it('answers 404 for a conversation the admin may not see', function (string $admin, string $thread) {
    $this->actingAs($this->{$admin})->get(ThreadResource::getUrl('view', ['record' => $this->{$thread}]))->assertNotFound();
})->with([['community', 'projectThread'], ['dm', 'support']]);

it('lets an admin answer and add an internal note from the shared conversation', function () {
    Livewire::actingAs($this->community)->test(Conversation::class, ['thread' => $this->support])
        ->set('body', 'We are checking with finance.')->call('send')->assertHasNoErrors()
        ->set('body', 'Note to self: ask Priya.')->set('internal', true)->call('send')->assertHasNoErrors();

    Livewire::actingAs($this->contributor)->test(Conversation::class, ['thread' => $this->support])
        ->assertSee('We are checking with finance.')->assertDontSee('ask Priya');
});

it('shows the project conversation on the project page for delivery managers only', function () {
    expect(ThreadsRelationManager::canViewForRecord($this->project, EditProject::class))->toBeFalse();

    $this->actingAs($this->dm);
    expect(ThreadsRelationManager::canViewForRecord($this->project, EditProject::class))->toBeTrue();

    Livewire::actingAs($this->dm)->test(ThreadsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
        ->assertCanSeeTableRecords([$this->projectThread]);

    $this->actingAs($this->finance);
    expect(ThreadsRelationManager::canViewForRecord($this->project, EditProject::class))->toBeFalse();
});

it('marks an admin-posted reply to a client as unread for the other admins but not the sender', function () {
    $other = adminUser(AdminRole::SuperAdmin);
    app(PostMessage::class)($this->projectThread, $this->dm, 'Hello team');

    $unread = fn ($who) => Thread::query()->visibleTo($who)->withUnread($who)->whereKey($this->projectThread->id)->first()->unread_count;

    expect($unread($other))->toBe(1)->and($unread($this->dm))->toBe(0);
});
