<?php

use App\Actions\Payouts\ApprovePayout;
use App\Enums\AdminRole;
use App\Enums\InvoiceStatus;
use App\Enums\PayoutStatus;
use App\Enums\UserStatus;
use App\Models\Assignment;
use App\Models\InvoiceRef;
use App\Models\NotificationPreference;
use App\Models\Payout;
use App\Models\Thread;
use App\Notifications\GroibeNotification;
use App\Notifications\InvoicesOverdue;
use App\Notifications\NewMessage;
use App\Notifications\PayoutStatusChanged;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;

/** A stand-in notification in the locked `security` category, to prove the dispatcher itself cannot be told to skip it. */
class SecurityNoticeForTest extends GroibeNotification
{
    public function category(): string
    {
        return 'security';
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['title' => 'Your password was changed'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your password was changed')->line('If this was not you, contact Groibe.');
    }
}

beforeEach(function () {
    Notification::fake();
    $this->prefs = new NotificationPreferences;
});

it('applies a person\'s choice to the real notification of that category and no other', function () {
    $user = contributorUser();
    $this->prefs->set($user, 'message', true, false);
    $this->prefs->set($user, 'payout', false, true);

    $dispatcher = app(NotificationDispatcher::class);
    $dispatcher->send($user, NewMessage::for(Thread::factory()->create()));
    $dispatcher->send($user, new PayoutStatusChanged('Project X', PayoutStatus::Paid));

    Notification::assertSentTo($user, NewMessage::class, fn ($n) => $n->channels === ['database']);
    Notification::assertSentTo($user, PayoutStatusChanged::class, fn ($n) => $n->channels === ['mail']);
});

it('keeps the payout category switch working through the real payout Action', function () {
    $finance = adminUser(AdminRole::FinanceOps);
    $seat = Assignment::factory()->active()->create(['agreed_amount' => 5000]);
    $payout = Payout::factory()->create(['assignment_id' => $seat->id, 'amount' => 100]);

    $this->prefs->set($seat->user, 'payout', false, false);
    app(ApprovePayout::class)($payout, $finance);

    Notification::assertNothingSent();
});

it('lets finance mute the overdue digest like any other category', function () {
    $finance = adminUser(AdminRole::FinanceOps);
    $super = adminUser(AdminRole::SuperAdmin);
    $this->prefs->set($finance, 'payout', false, false);

    InvoiceRef::factory()->status(InvoiceStatus::Sent)->create(['due_at' => now()->subDay()->toDateString()]);
    $this->artisan('groibe:mark-overdue-invoices')->assertSuccessful();

    Notification::assertNotSentTo($finance, InvoicesOverdue::class);
    Notification::assertSentTo($super, InvoicesOverdue::class);
});

describe('security is locked on', function () {
    it('always sends both channels, whatever the person chose or the database holds', function () {
        $user = contributorUser();

        NotificationPreference::query()->create(['user_id' => $user->id, 'category' => 'security', 'database' => false, 'mail' => false]);

        app(NotificationDispatcher::class)->send($user, new SecurityNoticeForTest);

        Notification::assertSentTo($user, SecurityNoticeForTest::class, fn ($n) => $n->channels === ['database', 'mail']);
    });

    it('cannot be saved off through the service, in any combination', function (bool $database, bool $mail) {
        expect(fn () => $this->prefs->set(contributorUser(), 'security', $database, $mail))->toThrow(InvalidArgumentException::class);
        expect(NotificationPreference::query()->count())->toBe(0);
    })->with([[false, false], [false, true], [true, false], [true, true]]);

    it('is the only locked category', function () {
        $locked = collect($this->prefs->categories())->filter(fn (string $c) => $this->prefs->isLocked($c))->values()->all();

        expect($locked)->toBe(['security'])->and(config('groibe.notifications.locked_categories'))->toBe(['security']);
    });
});

it('does not send anything to a suspended account, whatever its preferences', function () {
    $suspended = contributorUser();
    $suspended->forceFill(['status' => UserStatus::Suspended])->save();

    app(NotificationDispatcher::class)->send($suspended, new SecurityNoticeForTest);

    Notification::assertNothingSent();
});

it('treats a person with no stored choices as wanting everything, in every category', function () {
    $user = contributorUser();

    foreach ($this->prefs->categories() as $category) {
        expect($this->prefs->channelsFor($user, $category))->toBe(['database', 'mail'], $category);
    }
});

it('keeps one person\'s choices from changing another person\'s notifications', function () {
    $quiet = contributorUser();
    $normal = contributorUser();
    $this->prefs->set($quiet, 'message', false, false);

    app(NotificationDispatcher::class)->send([$quiet, $normal], NewMessage::for(Thread::factory()->create()));

    Notification::assertNotSentTo($quiet, NewMessage::class);
    Notification::assertSentTo($normal, NewMessage::class, fn ($n) => $n->channels === ['database', 'mail']);
});
