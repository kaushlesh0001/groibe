<?php

use App\Enums\AdminRole;
use App\Enums\EmailStatus;
use App\Filament\Admin\Resources\EmailLogs\EmailLogResource;
use App\Filament\Admin\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Jobs\SendContactMessageEmail;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use App\Models\EmailLog;
use App\Models\Thread;
use App\Notifications\GroibeNotification;
use App\Notifications\NewMessage;
use App\Services\Notifications\EmailLogger;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(fn () => config(['groibe.contact_email' => 'contact@groibe.test']));

/** A notification whose email cannot be built, to exercise failure logging. */
class ExplodingNotification extends GroibeNotification
{
    public int $tries = 1;

    public function category(): string
    {
        return 'task';
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['title' => 'Boom'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        throw new RuntimeException('SMTP said no');
    }
}

it('records a sent notification email with the template and the user', function () {
    $user = contributorUser();

    $user->notify(NewMessage::for(Thread::factory()->create(['subject' => 'Project chat'])));

    $row = EmailLog::query()->sole();

    expect($row->status)->toBe(EmailStatus::Sent)
        ->and($row->template)->toBe('NewMessage')
        ->and($row->to)->toBe($user->email)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->sent_at)->not->toBeNull()
        ->and($row->error)->toBeNull();
});

it('never stores the message body or subject text', function () {
    contributorUser()->notify(NewMessage::for(Thread::factory()->create(['subject' => 'Very secret subject'])));

    expect(json_encode(EmailLog::query()->sole()->getAttributes()))->not->toContain('secret');
});

it('records a queued notification that fails for good, and keeps the error short', function () {
    $user = contributorUser();

    try {
        $user->notify(new ExplodingNotification);
    } catch (RuntimeException) {
        // The sync queue rethrows, as the real queue would after its last try.
    }

    $row = EmailLog::query()->sole();

    expect($row->status)->toBe(EmailStatus::Failed)
        ->and($row->template)->toBe('ExplodingNotification')
        ->and($row->to)->toBe($user->email)
        ->and($row->error)->toContain('SMTP said no')
        ->and($row->sent_at)->toBeNull();
});

it('trims a very long error', function () {
    app(EmailLogger::class)->failed('X', 'a@example.test', new RuntimeException(str_repeat('e', 2000)));

    expect(strlen(EmailLog::query()->sole()->error))->toBeLessThanOrEqual(500);
});

describe('the contact form email', function () {
    it('is logged as sent, with no user, when the team email goes out', function () {
        $this->post(route('contact.store'), ['name' => 'Asha', 'email' => 'asha@example.test', 'subject' => 'Hi', 'message' => 'A question about Groibe please.'])->assertRedirect();

        $row = EmailLog::query()->sole();

        expect($row->template)->toBe('ContactMessageReceived')
            ->and($row->to)->toBe('contact@groibe.test')
            ->and($row->status)->toBe(EmailStatus::Sent)
            ->and($row->user_id)->toBeNull();
    });

    it('is logged as failed after the last try, and the stored message is kept', function () {
        $message = ContactMessage::factory()->create();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection refused'));

        $job = new SendContactMessageEmail($message->id);

        try {
            $job->handle();
        } catch (RuntimeException $e) {
            $job->failed($e);
        }

        $row = EmailLog::query()->sole();

        expect($row->status)->toBe(EmailStatus::Failed)
            ->and($row->template)->toBe(class_basename(ContactMessageReceived::class))
            ->and($row->to)->toBe('contact@groibe.test')
            ->and($row->error)->toContain('Connection refused')
            ->and(ContactMessage::query()->find($message->id)->email_sent_at)->toBeNull();
    });

    it('logs nothing and does not fail when no contact address is set', function () {
        config(['groibe.contact_email' => null]);

        (new SendContactMessageEmail(ContactMessage::factory()->create()->id))->failed(new RuntimeException('x'));

        expect(EmailLog::query()->count())->toBe(0);
    });
});

describe('the Email Log admin screen', function () {
    beforeEach(function () {
        $this->log = EmailLog::query()->create(['template' => 'NewMessage', 'to' => 'x@example.test', 'status' => EmailStatus::Failed, 'error' => 'boom']);
    });

    it('is for super admins only', function (AdminRole $role, bool $allowed) {
        $this->actingAs(adminUser($role));

        expect(EmailLogResource::canViewAny())->toBe($allowed);
    })->with([
        [AdminRole::SuperAdmin, true],
        [AdminRole::DeliveryManager, false],
        [AdminRole::CommunityManager, false],
        [AdminRole::FinanceOps, false],
    ]);

    it('lists failures by default and offers no create, edit or delete', function () {
        $sent = EmailLog::query()->create(['template' => 'SowSent', 'to' => 'y@example.test', 'status' => EmailStatus::Sent, 'sent_at' => now()]);
        $admin = adminUser(AdminRole::SuperAdmin);

        Livewire::actingAs($admin)->test(ListEmailLogs::class)
            ->assertCanSeeTableRecords([$this->log])->assertCanNotSeeTableRecords([$sent])
            ->filterTable('status', 'sent')->assertCanSeeTableRecords([$sent]);

        expect($admin->can('create', EmailLog::class))->toBeFalse()
            ->and($admin->can('update', $this->log))->toBeFalse()
            ->and($admin->can('delete', $this->log))->toBeFalse();
    });

    it('answers 403 to other admins and clients', function () {
        $this->actingAs(adminUser(AdminRole::FinanceOps))->get(EmailLogResource::getUrl('index'))->assertForbidden();
    });
});
