<?php

use App\Actions\Messages\GetProjectThread;
use App\Actions\Messages\StartSupportThread;
use App\Enums\AdminRole;
use App\Enums\EmailStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\PayoutStatus;
use App\Enums\ProjectStatus;
use App\Filament\Admin\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Filament\Admin\Resources\InvoiceRefs\Pages\ListInvoiceRefs;
use App\Filament\Admin\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Admin\Resources\Threads\Pages\ListThreads;
use App\Livewire\Notifications\Bell;
use App\Livewire\Notifications\NotificationCentre;
use App\Livewire\Settings\NotificationSettings;
use App\Livewire\Shared\Messages\Conversation;
use App\Livewire\Shared\Messages\Inbox;
use App\Models\Assignment;
use App\Models\EmailLog;
use App\Models\InvoiceRef;
use App\Models\Message;
use App\Models\Payout;
use App\Models\Project;
use App\Models\Thread;
use App\Models\User;
use App\Notifications\NewMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Review Prompt, Pass 7: no N+1 on any new page. Each page is rendered once to warm caches, then its queries are counted with a
 * small data set and again after the data grows. The two counts must match.
 */

/** Number of queries one render of the page makes, after a warm-up render. */
function pageQueries(User $as, string $component, array $params = []): int
{
    Livewire::actingAs($as)->test($component, $params);

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::actingAs($as)->test($component, $params);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

beforeEach(function () {
    Storage::fake('local');

    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->finance = adminUser(AdminRole::FinanceOps);
    $this->super = adminUser(AdminRole::SuperAdmin);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $this->dm->id]);
    $this->owner = clientUser($this->project->organization, OrgRole::Owner);
    $this->me = contributorIn(OnboardingState::Active);
    $this->seat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->me->id, 'agreed_amount' => 900000]);
    $this->thread = app(GetProjectThread::class)->ensure($this->project, $this->dm);
});

it('keeps the message inbox flat as conversations are added', function () {
    foreach (range(1, 3) as $i) {
        app(StartSupportThread::class)($this->me, "Question {$i}", 'Hello');
    }

    $few = pageQueries($this->me, Inbox::class);

    foreach (range(4, 14) as $i) {
        app(StartSupportThread::class)($this->me, "Question {$i}", 'Hello');
    }

    expect(pageQueries($this->me, Inbox::class))->toBe($few);
});

it('keeps a conversation page flat as messages, authors and files are added', function () {
    $others = User::factory()->count(6)->create();

    Message::factory()->count(4)->create(['thread_id' => $this->thread->id, 'user_id' => $this->dm->id]);
    $few = pageQueries($this->owner, Conversation::class, ['thread' => $this->thread]);

    foreach ($others as $other) {
        $message = Message::factory()->create(['thread_id' => $this->thread->id, 'user_id' => $other->id]);
        $message->addMedia(UploadedFile::fake()->create("doc-{$other->id}.pdf", 5, 'application/pdf'))->toMediaCollection(Message::FILES);
    }

    Message::factory()->count(20)->create(['thread_id' => $this->thread->id, 'user_id' => $this->owner->id]);

    expect(pageQueries($this->owner, Conversation::class, ['thread' => $this->thread->fresh()]))->toBe($few);
});

it('keeps the notification centre, the bell and the preferences page flat', function () {
    foreach (range(1, 3) as $i) {
        $this->me->notify(NewMessage::for(Thread::factory()->create(['subject' => "Subject {$i}"])));
    }

    $centreFew = pageQueries($this->me, NotificationCentre::class);
    $bellFew = pageQueries($this->me, Bell::class);
    $settingsFew = pageQueries($this->me, NotificationSettings::class);

    foreach (range(4, 18) as $i) {
        $this->me->notify(NewMessage::for(Thread::factory()->create(['subject' => "Subject {$i}"])));
    }

    expect(pageQueries($this->me, NotificationCentre::class))->toBe($centreFew)
        ->and(pageQueries($this->me, Bell::class))->toBe($bellFew)
        ->and(pageQueries($this->me, NotificationSettings::class))->toBe($settingsFew);
});

it('keeps the finance and admin lists flat as rows are added', function () {
    $payout = fn (PayoutStatus $status) => Payout::factory()->status($status)->create(['assignment_id' => $this->seat->id, 'amount' => 1]);

    $payout(PayoutStatus::Planned);
    InvoiceRef::factory()->status(InvoiceStatus::Sent)->create(['project_id' => $this->project->id]);
    EmailLog::query()->create(['template' => 'NewMessage', 'to' => 'a@example.test', 'status' => EmailStatus::Failed, 'user_id' => $this->me->id]);

    $few = [
        'payouts' => pageQueries($this->finance, ListPayouts::class),
        'invoices' => pageQueries($this->finance, ListInvoiceRefs::class),
        'threads' => pageQueries($this->dm, ListThreads::class),
        'emails' => pageQueries($this->super, ListEmailLogs::class),
    ];

    foreach (PayoutStatus::cases() as $status) {
        foreach (range(1, 3) as $_) {
            $payout($status);
        }
    }

    foreach (range(1, 8) as $_) {
        InvoiceRef::factory()->status(InvoiceStatus::Sent)->create(['project_id' => Project::factory()->create()->id]);
        EmailLog::query()->create(['template' => 'SowSent', 'to' => fake()->safeEmail(), 'status' => EmailStatus::Failed, 'user_id' => User::factory()->create()->id]);
        Thread::factory()->forProject()->create();
        app(StartSupportThread::class)(contributorIn(OnboardingState::Active), 'Another question', 'Hi');
    }

    expect(pageQueries($this->finance, ListPayouts::class))->toBe($few['payouts'])
        ->and(pageQueries($this->finance, ListInvoiceRefs::class))->toBe($few['invoices'])
        ->and(pageQueries($this->dm, ListThreads::class))->toBe($few['threads'])
        ->and(pageQueries($this->super, ListEmailLogs::class))->toBe($few['emails']);
});
