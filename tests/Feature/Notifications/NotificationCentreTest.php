<?php

use App\Actions\Notifications\MarkNotificationsRead;
use App\Enums\AdminRole;
use App\Livewire\Notifications\Bell;
use App\Livewire\Notifications\NotificationCentre;
use App\Notifications\NewMessage;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;

/** A stored notification for a person, as the database channel would write it. */
function storeNotification($user, array $data = [], bool $read = false): DatabaseNotification
{
    return $user->notifications()->create([
        'id' => (string) str()->uuid(),
        'type' => NewMessage::class,
        'data' => ['title' => 'Something happened', 'body' => 'Details', 'url' => '/app/client', ...$data],
        'read_at' => $read ? now() : null,
    ]);
}

beforeEach(function () {
    $this->me = clientUser();
    $this->other = clientUser();
});

describe('the bell', function () {
    it('shows my unread count and ignores other people and read ones', function () {
        storeNotification($this->me);
        storeNotification($this->me);
        storeNotification($this->me, read: true);
        storeNotification($this->other);

        Livewire::actingAs($this->me)->test(Bell::class)->assertSee('2 unread')->assertSeeHtml('wire:poll.60s.visible');
    });

    it('has no badge when nothing is unread', function () {
        Livewire::actingAs($this->me)->test(Bell::class)->assertDontSee('unread');
    });

    it('is in the page layout for every signed-in role', function () {
        $this->actingAs($this->me)->get(route('client.dashboard'))->assertSeeHtml('aria-label="Notifications');
        $this->actingAs(contributorUser())->get(route('settings.profile'))->assertSeeHtml('aria-label="Notifications');
    });
});

describe('the notification centre', function () {
    it('lists only my own notifications', function () {
        storeNotification($this->me, ['title' => 'Mine only']);
        storeNotification($this->other, ['title' => 'Not for me']);

        Livewire::actingAs($this->me)->test(NotificationCentre::class)->assertSee('Mine only')->assertDontSee('Not for me');
    });

    it('is closed to guests and open to every role', function () {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));

        foreach ([$this->me, contributorUser(), adminUser(AdminRole::DeliveryManager)] as $user) {
            $this->actingAs($user)->get(route('notifications.index'))->assertOk();
        }
    });

    it('marks one notification read, and all of them', function () {
        $a = storeNotification($this->me);
        $b = storeNotification($this->me);
        $theirs = storeNotification($this->other);

        Livewire::actingAs($this->me)->test(NotificationCentre::class)->call('markRead', $a->id);
        expect($a->fresh()->read_at)->not->toBeNull()->and($b->fresh()->read_at)->toBeNull();

        Livewire::actingAs($this->me)->test(NotificationCentre::class)->call('markAllRead');
        expect($b->fresh()->read_at)->not->toBeNull()->and($theirs->fresh()->read_at)->toBeNull();
    });

    it('cannot mark or open somebody else\'s notification', function () {
        $theirs = storeNotification($this->other);

        Livewire::actingAs($this->me)->test(NotificationCentre::class)->call('markRead', $theirs->id);
        expect($theirs->fresh()->read_at)->toBeNull()
            ->and(app(MarkNotificationsRead::class)->one($this->me, $theirs->id))->toBeNull();

        Livewire::actingAs($this->me)->test(NotificationCentre::class)->call('open', $theirs->id)->assertNotFound();
        expect($theirs->fresh()->read_at)->toBeNull();
    });

    it('opens the link and marks the notification read', function () {
        $n = storeNotification($this->me, ['url' => route('client.projects.index')]);

        Livewire::actingAs($this->me)->test(NotificationCentre::class)->call('open', $n->id)->assertRedirect(route('client.projects.index'));

        expect($n->fresh()->read_at)->not->toBeNull();
    });

    it('never follows a link that leaves the site', function (string $url) {
        $n = storeNotification($this->me, ['url' => $url]);

        Livewire::actingAs($this->me)->test(NotificationCentre::class)->call('open', $n->id)->assertNoRedirect();

        expect($n->fresh()->read_at)->not->toBeNull();
    })->with(['https://evil.example/phish', '//evil.example/phish']);

    it('pages the list', function () {
        foreach (range(1, 25) as $i) {
            storeNotification($this->me, ['title' => "Item {$i}"]);
        }

        Livewire::actingAs($this->me)->test(NotificationCentre::class)->assertViewHas('notifications', fn ($p) => $p->count() === 20 && $p->total() === 25);
    });
});
