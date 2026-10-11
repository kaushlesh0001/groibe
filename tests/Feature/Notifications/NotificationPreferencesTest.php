<?php

use App\Livewire\Settings\NotificationSettings;
use App\Models\NotificationPreference;
use App\Notifications\NewMessage;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->me = contributorUser();
    $this->prefs = fn () => new NotificationPreferences;
    $this->send = function ($user) {
        Notification::fake();
        app(NotificationDispatcher::class)->send($user, new NewMessage('01ABC', 'Subject'));
    };
});

it('sends both channels when nothing is stored', function () {
    expect(($this->prefs)()->channelsFor($this->me, 'message'))->toBe(['database', 'mail']);

    ($this->send)($this->me);

    Notification::assertSentTo($this->me, NewMessage::class, fn ($n) => $n->channels === ['database', 'mail']);
});

it('honours a per-category choice for that person only', function () {
    $other = contributorUser();
    ($this->prefs)()->set($this->me, 'message', true, false);

    expect(($this->prefs)()->channelsFor($this->me, 'message'))->toBe(['database'])
        ->and(($this->prefs)()->channelsFor($this->me, 'task'))->toBe(['database', 'mail'])
        ->and(($this->prefs)()->channelsFor($other, 'message'))->toBe(['database', 'mail']);

    ($this->send)($this->me);
    Notification::assertSentTo($this->me, NewMessage::class, fn ($n) => $n->channels === ['database']);
});

it('sends nothing at all when both channels are off for the category', function () {
    ($this->prefs)()->set($this->me, 'message', false, false);

    ($this->send)($this->me);

    Notification::assertNothingSent();
});

it('updates an existing choice instead of adding a second row', function () {
    ($this->prefs)()->set($this->me, 'message', false, true);
    ($this->prefs)()->set($this->me, 'message', true, true);

    expect(NotificationPreference::query()->where('user_id', $this->me->id)->count())->toBe(1)
        ->and(($this->prefs)()->channelsFor($this->me, 'message'))->toBe(['database', 'mail']);
});

describe('security notifications', function () {
    it('cannot be switched off', function () {
        expect(fn () => ($this->prefs)()->set($this->me, 'security', false, false))->toThrow(InvalidArgumentException::class);
        expect(NotificationPreference::query()->count())->toBe(0);
    });

    it('stay on even if a row was forced off in the database', function () {
        NotificationPreference::query()->create(['user_id' => $this->me->id, 'category' => 'security', 'database' => false, 'mail' => false]);

        expect(($this->prefs)()->channelsFor($this->me, 'security'))->toBe(['database', 'mail'])
            ->and(($this->prefs)()->all($this->me)['security'])->toBe(['database' => true, 'mail' => true]);
    });

    it('are shown locked on the preferences page and ignore a forged save', function () {
        Livewire::actingAs($this->me)->test(NotificationSettings::class)
            ->assertSee('Always on')
            ->set('choices.security.mail', false)->set('choices.security.database', false)
            ->call('save');

        expect(NotificationPreference::query()->where('category', 'security')->exists())->toBeFalse()
            ->and(($this->prefs)()->channelsFor($this->me, 'security'))->toBe(['database', 'mail']);
    });
});

it('rejects an unknown category', function () {
    expect(fn () => ($this->prefs)()->set($this->me, 'made_up', false, false))->toThrow(InvalidArgumentException::class);
});

describe('the preferences page', function () {
    it('saves the choices for the signed-in person only', function () {
        $other = contributorUser();

        Livewire::actingAs($this->me)->test(NotificationSettings::class)
            ->set('choices.message.mail', false)->set('choices.task.database', false)->call('save')
            ->assertSee('Saved.');

        expect(($this->prefs)()->channelsFor($this->me, 'message'))->toBe(['database'])
            ->and(($this->prefs)()->channelsFor($this->me, 'task'))->toBe(['mail'])
            ->and(($this->prefs)()->channelsFor($other, 'message'))->toBe(['database', 'mail']);
    });

    it('lists every category with a label and is closed to guests', function () {
        $this->get(route('settings.notifications'))->assertRedirect(route('login'));

        $page = $this->actingAs($this->me)->get(route('settings.notifications'))->assertOk();

        foreach (config('groibe.notifications.labels') as $label) {
            $page->assertSee($label['name']);
        }

        expect(array_keys(config('groibe.notifications.labels')))->toEqualCanonicalizing(config('groibe.notifications.categories'));
    });
});
