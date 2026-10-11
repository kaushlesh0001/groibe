<?php

use App\Actions\Messages\GetProjectThread;
use App\Actions\Messages\MarkThreadRead;
use App\Actions\Messages\PostMessage;
use App\Actions\Messages\StartSupportThread;
use App\Enums\AdminRole;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Livewire\Shared\Messages\Conversation;
use App\Livewire\Shared\Messages\Inbox;
use App\Models\Assignment;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Thread;
use App\Notifications\NewMessage;
use App\Services\Files\SignedFileUrl;
use App\Services\Messaging\UnreadMessages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Two organizations, each with a client owner and an active project, and three contributors: one on project A only,
 * one on project B only, one on both. Every statement below is about who can reach which of the two conversations.
 */
beforeEach(function () {
    Storage::fake('local');
    Notification::fake();

    $this->dmA = adminUser(AdminRole::DeliveryManager);
    $this->dmB = adminUser(AdminRole::DeliveryManager);

    $this->orgA = Organization::factory()->verified()->create(['name' => 'Alpha Foods']);
    $this->orgB = Organization::factory()->verified()->create(['name' => 'Beta Steel']);
    $this->ownerA = clientUser($this->orgA, OrgRole::Owner);
    $this->ownerB = clientUser($this->orgB, OrgRole::Owner);

    $this->projectA = Project::factory()->status(ProjectStatus::Active)->create(['organization_id' => $this->orgA->id, 'delivery_manager_id' => $this->dmA->id]);
    $this->projectB = Project::factory()->status(ProjectStatus::Active)->create(['organization_id' => $this->orgB->id, 'delivery_manager_id' => $this->dmB->id]);

    $this->onlyA = contributorIn(OnboardingState::Active);
    $this->onlyB = contributorIn(OnboardingState::Active);
    $this->both = contributorIn(OnboardingState::Active);

    foreach ([[$this->projectA, $this->onlyA], [$this->projectB, $this->onlyB], [$this->projectA, $this->both], [$this->projectB, $this->both]] as [$project, $user]) {
        Assignment::factory()->active()->create(['project_id' => $project->id, 'user_id' => $user->id]);
    }

    $this->threadA = app(GetProjectThread::class)->ensure($this->projectA, $this->dmA);
    $this->threadB = app(GetProjectThread::class)->ensure($this->projectB, $this->dmB);

    $this->visibleIds = fn ($who) => Thread::query()->visibleTo($who)->pluck('id')->sort()->values()->all();
});

it('gives every person exactly the conversations of the projects they belong to', function (string $who, array $threads) {
    $expected = collect($threads)->map(fn (string $name) => $this->{$name}->id)->sort()->values()->all();

    expect(($this->visibleIds)($this->{$who}))->toBe($expected);
})->with([
    'client A' => ['ownerA', ['threadA']],
    'client B' => ['ownerB', ['threadB']],
    'contributor on A only' => ['onlyA', ['threadA']],
    'contributor on B only' => ['onlyB', ['threadB']],
    'contributor on both' => ['both', ['threadA', 'threadB']],
    'manager of A' => ['dmA', ['threadA', 'threadB']],
]);

it('lets a delivery manager moderate every project, as the matrix says, but only that', function () {
    $community = adminUser(AdminRole::CommunityManager);
    $finance = adminUser(AdminRole::FinanceOps);

    expect(($this->visibleIds)($this->dmA))->toBe([$this->threadA->id, $this->threadB->id])
        ->and(($this->visibleIds)($community))->toBe([])
        ->and(($this->visibleIds)($finance))->toBe([]);
});

it('keeps a client of one organization out of another organization\'s project, by every route', function () {
    $this->actingAs($this->ownerA)->get(route('client.messages.show', $this->threadB))->assertNotFound();
    $this->actingAs($this->ownerA)->get(route('client.messages.project', $this->projectB))->assertNotFound();
    $this->actingAs($this->ownerB)->get(route('client.messages.show', $this->threadA))->assertNotFound();

    expect(fn () => app(GetProjectThread::class)($this->projectB, $this->ownerA))->toThrow(AuthorizationException::class)
        ->and(fn () => app(PostMessage::class)($this->threadB, $this->ownerA, 'Hello from the wrong org'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MarkThreadRead::class)($this->threadB, $this->ownerA))->toThrow(AuthorizationException::class)
        ->and(Message::query()->count())->toBe(0);
});

it('keeps a contributor on one project out of the other project\'s thread, by every route', function () {
    $this->actingAs($this->onlyA)->get(route('contributor.messages.show', $this->threadB))->assertNotFound();
    $this->actingAs($this->onlyA)->get(route('contributor.messages.project', $this->projectB))->assertNotFound();

    expect(fn () => app(PostMessage::class)($this->threadB, $this->onlyA, 'Wrong project'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(GetProjectThread::class)($this->projectB, $this->onlyA))->toThrow(AuthorizationException::class);
});

it('shows a person only their own threads in the inbox, never a title from somewhere else', function () {
    $this->threadA->update(['subject' => 'Alpha secret project']);
    $this->threadB->update(['subject' => 'Beta secret project']);
    app(StartSupportThread::class)($this->onlyB, 'Onlys B private matter', 'Hello');

    Livewire::actingAs($this->ownerA)->test(Inbox::class)
        ->assertSee('Alpha secret project')->assertDontSee('Beta secret project')->assertDontSee('Onlys B private matter');

    Livewire::actingAs($this->onlyA)->test(Inbox::class)
        ->assertSee('Alpha secret project')->assertDontSee('Beta secret project')->assertDontSee('Onlys B private matter');

    Livewire::actingAs($this->both)->test(Inbox::class)->assertSee('Alpha secret project')->assertSee('Beta secret project')->assertDontSee('Onlys B private matter');
});

it('does not let a conversation page be repointed at another thread from the browser', function () {
    $component = Livewire::actingAs($this->ownerA)->test(Conversation::class, ['thread' => $this->threadA]);

    // The thread property is locked: changing it from the client side is refused, not followed.
    expect(fn () => $component->set('thread', $this->threadB->id))->toThrow(Exception::class);

    $component->assertSee($this->threadA->subject)->assertDontSee($this->threadB->subject);
});

it('keeps one project\'s messages out of another person\'s unread count and badge', function () {
    app(PostMessage::class)($this->threadB, $this->dmB, 'News for project B');

    expect((new UnreadMessages)->count($this->ownerB))->toBe(1)
        ->and((new UnreadMessages)->count($this->onlyB))->toBe(1)
        ->and((new UnreadMessages)->count($this->both))->toBe(1)
        ->and((new UnreadMessages)->count($this->ownerA))->toBe(0)
        ->and((new UnreadMessages)->count($this->onlyA))->toBe(0);
});

it('sends notifications only to the people of the project the message was posted in', function () {
    app(PostMessage::class)($this->threadB, $this->dmB, 'News for project B');

    Notification::assertSentTo([$this->ownerB, $this->onlyB, $this->both], NewMessage::class);
    Notification::assertNotSentTo([$this->ownerA, $this->onlyA, $this->dmA], NewMessage::class);
});

it('does not serve a file from one project\'s conversation to the other project\'s people', function () {
    $message = app(PostMessage::class)($this->threadA, $this->dmA, 'Attached', [UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf')]);
    $url = app(SignedFileUrl::class)->for($message->getFirstMedia(Message::FILES));

    $this->actingAs($this->ownerA)->get($url)->assertOk();
    $this->actingAs($this->onlyA)->get($url)->assertOk();
    $this->actingAs($this->both)->get($url)->assertOk();

    $this->actingAs($this->ownerB)->get($url)->assertForbidden();
    $this->actingAs($this->onlyB)->get($url)->assertForbidden();
    $this->actingAs($this->dmB)->get($url)->assertOk(); // the matrix lets any delivery manager moderate
});

it('lists a support thread only for its owner even when two people share a project', function () {
    $mine = app(StartSupportThread::class)($this->both, 'Both support question', 'Please help');

    foreach ([$this->onlyA, $this->onlyB, $this->ownerA, $this->ownerB, $this->dmA, $this->dmB] as $other) {
        expect(($this->visibleIds)($other))->not->toContain($mine->id);
    }

    expect(($this->visibleIds)($this->both))->toContain($mine->id);
});

describe('internal notes leave no trace for outsiders', function () {
    it('do not change what the client or contributor inbox says about the last message', function () {
        app(PostMessage::class)($this->threadA, $this->dmA, 'Public hello');
        $this->threadA->forceFill(['last_message_at' => now()->subDays(3)])->save();
        Message::query()->where('thread_id', $this->threadA->id)->update(['created_at' => now()->subDays(3)]);

        app(PostMessage::class)($this->threadA, $this->dmA, 'Internal note from just now', internal: true);

        foreach ([$this->ownerA, $this->onlyA] as $outsider) {
            Livewire::actingAs($outsider)->test(Inbox::class)->assertSee('3 days ago')->assertDontSee('seconds ago')->assertDontSee('minute');
        }
    });

    it('do not reorder the inbox of an outsider', function () {
        $old = app(StartSupportThread::class)($this->both, 'Old support thread', 'Hi');
        Thread::query()->whereKey($old->id)->update(['last_message_at' => now()->subHour()->format('Y-m-d H:i:s.u')]);
        Message::query()->where('thread_id', $old->id)->update(['created_at' => now()->subHour()->format('Y-m-d H:i:s.u')]);
        app(PostMessage::class)($this->threadA, $this->dmA, 'Hello team', internal: false);
        Message::query()->where('thread_id', $this->threadA->id)->update(['created_at' => now()->subDays(2)->format('Y-m-d H:i:s.u')]);

        app(PostMessage::class)($this->threadA, $this->dmA, 'Internal now', internal: true);

        $order = Livewire::actingAs($this->both)->test(Inbox::class)->viewData('threads')->pluck('id')->all();

        expect($order)->toBe([$old->id, $this->threadA->id, $this->threadB->id]);
    });
});
