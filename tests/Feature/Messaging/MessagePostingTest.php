<?php

use App\Actions\Messages\GetProjectThread;
use App\Actions\Messages\PostMessage;
use App\Actions\Messages\StartSupportThread;
use App\Actions\Sows\SendSow;
use App\Enums\AdminRole;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Livewire\Shared\Messages\Conversation;
use App\Models\Assignment;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sow;
use App\Models\Thread;
use App\Notifications\NewMessage;
use App\Services\Files\SignedFileUrl;
use App\Services\Messaging\UnreadMessages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();

    $this->org = Organization::factory()->verified()->create(['name' => 'Acme Foods']);
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->otherAdmin = adminUser(AdminRole::SuperAdmin);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['organization_id' => $this->org->id, 'delivery_manager_id' => $this->dm->id]);

    $this->owner = clientUser($this->org, OrgRole::Owner);
    $this->manager = clientUser($this->org, OrgRole::Manager);
    $this->viewer = clientUser($this->org, OrgRole::Viewer);

    $this->seat = contributorIn(OnboardingState::Active, [], ['name' => 'Meera Nair']);
    Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->seat->id]);
    $this->seat2 = contributorIn(OnboardingState::Active);
    Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->seat2->id]);
    $this->outsider = contributorIn(OnboardingState::Active);

    $this->thread = app(GetProjectThread::class)->ensure($this->project, $this->dm);
    $this->post = fn ($who, string $body, array $files = [], bool $internal = false) => app(PostMessage::class)($this->thread, $who, $body, $files, $internal);
});

describe('internal messages', function () {
    it('are never shown to clients or contributors, in the page or the query', function () {
        ($this->post)($this->dm, 'Margin on this one is thin, keep the scope tight.', internal: true);
        ($this->post)($this->dm, 'Welcome to the project.');

        foreach ([$this->owner, $this->manager, $this->seat, $this->seat2] as $person) {
            expect(Message::query()->visibleTo($person)->where('thread_id', $this->thread->id)->pluck('body')->all())->toBe(['Welcome to the project.']);

            Livewire::actingAs($person)->test(Conversation::class, ['thread' => $this->thread])
                ->assertSee('Welcome to the project.')
                ->assertDontSee('Margin on this one')
                ->assertDontSee('Internal note');
        }

        Livewire::actingAs($this->otherAdmin)->test(Conversation::class, ['thread' => $this->thread])
            ->assertSee('Margin on this one')->assertSee('Internal note');
    });

    it('can only be posted by an admin who may moderate the thread', function (string $who) {
        $actor = match ($who) {
            'client_owner' => $this->owner,
            'contributor' => $this->seat,
        };

        expect(fn () => ($this->post)($actor, 'Sneaky', internal: true))->toThrow(AuthorizationException::class)
            ->and(Message::query()->where('body', 'Sneaky')->exists())->toBeFalse();
    })->with(['client_owner', 'contributor']);

    it('ignores a forged internal flag from a client in the component', function () {
        Livewire::actingAs($this->owner)->test(Conversation::class, ['thread' => $this->thread])
            ->set('body', 'Hello team')->set('internal', true)->call('send')
            ->assertHasErrors('body');

        expect(Message::query()->where('body', 'Hello team')->exists())->toBeFalse();
    });

    it('notify admins only, and the files on them are not downloadable by clients or contributors', function () {
        $message = ($this->post)($this->dm, 'Internal budget note', [UploadedFile::fake()->create('costing.pdf', 10, 'application/pdf')], true);
        $media = $message->getFirstMedia(Message::FILES);
        $url = app(SignedFileUrl::class)->for($media);

        Notification::assertNotSentTo([$this->owner, $this->manager, $this->viewer, $this->seat, $this->seat2, $this->outsider], NewMessage::class);

        $this->actingAs($this->owner)->get($url)->assertForbidden();
        $this->actingAs($this->seat)->get($url)->assertForbidden();
        $this->actingAs($this->dm)->get($url)->assertOk();
    });
});

describe('who is told about a message', function () {
    it('tells the other people on the project but not the sender, viewers or outsiders', function () {
        ($this->post)($this->owner, 'Can we move the review to Friday?');

        Notification::assertSentTo([$this->dm, $this->manager, $this->seat, $this->seat2], NewMessage::class);
        Notification::assertNotSentTo([$this->owner, $this->viewer, $this->outsider], NewMessage::class);
    });

    it('carries no message text', function () {
        ($this->post)($this->owner, 'A very private sentence');

        Notification::assertSentTo($this->seat, NewMessage::class, function (NewMessage $n) {
            return ! str_contains(json_encode($n->toArray($this->seat)), 'private sentence')
                && ! str_contains($n->toMail($this->seat)->render(), 'private sentence');
        });
    });

    it('tells the support admins when a user writes, and the user when an admin answers', function () {
        $community = adminUser(AdminRole::CommunityManager);
        $support = app(StartSupportThread::class)($this->seat, 'Question', 'Hello Groibe');

        Notification::assertSentTo($community, NewMessage::class);
        Notification::assertNotSentTo([$this->dm, $this->owner], NewMessage::class);

        app(PostMessage::class)($support, $community, 'Hello Meera');

        Notification::assertSentTo($this->seat, NewMessage::class);
    });
});

describe('unread counts', function () {
    it('counts messages from others, clears when the thread is opened, and never counts my own', function () {
        ($this->post)($this->dm, 'First');
        ($this->post)($this->dm, 'Second');
        ($this->post)($this->seat, 'Mine');

        $counts = fn ($who) => Thread::query()->visibleTo($who)->withUnread($who)->whereKey($this->thread->id)->first()->unread_count;

        expect($counts($this->owner))->toBe(3)
            ->and($counts($this->seat))->toBe(2)
            ->and($counts($this->dm))->toBe(1);

        Livewire::actingAs($this->owner)->test(Conversation::class, ['thread' => $this->thread]);

        expect($counts($this->owner))->toBe(0)
            ->and($counts($this->seat))->toBe(2);

        ($this->post)($this->dm, 'Third');

        expect($counts($this->owner))->toBe(1)
            ->and((new UnreadMessages)->count($this->owner))->toBe(1)
            ->and((new UnreadMessages)->count($this->outsider))->toBe(0);
    });

    it('does not count internal notes for clients, but does for other admins', function () {
        ($this->post)($this->dm, 'Internal only', internal: true);

        expect((new UnreadMessages)->count($this->owner))->toBe(0)
            ->and((new UnreadMessages)->count($this->seat))->toBe(0)
            ->and((new UnreadMessages)->count($this->otherAdmin))->toBe(1);
    });
});

describe('names shown to each reader', function () {
    it('shows contributors to clients by first name, or as the project team when the team is hidden', function () {
        ($this->post)($this->seat, 'Draft ready for you.');

        Livewire::actingAs($this->owner)->test(Conversation::class, ['thread' => $this->thread])
            ->assertSee('Meera')->assertDontSee('Meera Nair');

        $this->project->update(['hide_team' => true]);

        Livewire::actingAs($this->owner)->test(Conversation::class, ['thread' => $this->thread->fresh()])
            ->assertDontSee('Meera')->assertSee('Project team');
    });

    it('shows client people to contributors only as Client (SCR-044), and staff as Groibe', function () {
        ($this->post)($this->owner, 'Thanks for the update.');
        ($this->post)($this->dm, 'You are welcome.');

        $firstName = explode(' ', $this->owner->name)[0];

        Livewire::actingAs($this->seat)->test(Conversation::class, ['thread' => $this->thread])
            ->assertSee('Client')->assertDontSee($firstName)->assertDontSee('Acme Foods')->assertSee('(Groibe)')->assertDontSee($this->dm->name);
    });

    it('still shows client people to clients and admins by first name and organization', function () {
        ($this->post)($this->owner, 'Thanks for the update.');

        $firstName = explode(' ', $this->owner->name)[0];

        Livewire::actingAs($this->manager)->test(Conversation::class, ['thread' => $this->thread])->assertSee("{$firstName}, Acme Foods");
        Livewire::actingAs($this->dm)->test(Conversation::class, ['thread' => $this->thread])->assertSee("{$firstName}, Acme Foods");
    });
});

describe('posting', function () {
    it('stores private attachments that only people in the thread can download', function () {
        $message = ($this->post)($this->seat, 'Here is the file.', [UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf')]);
        $url = app(SignedFileUrl::class)->for($message->getFirstMedia(Message::FILES));

        $this->actingAs($this->owner)->get($url)->assertOk()->assertDownload('notes.pdf');
        $this->actingAs($this->seat2)->get($url)->assertOk();
        $this->actingAs($this->outsider)->get($url)->assertForbidden();
        $this->actingAs(clientUser())->get($url)->assertForbidden();
        $this->actingAs($this->viewer)->get($url)->assertForbidden();
    });

    it('keeps support attachments away from everyone but the owner and support admins', function () {
        $support = app(StartSupportThread::class)($this->seat, 'Invoice copy', 'See attached.', [UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf')]);
        $url = app(SignedFileUrl::class)->for($support->messages()->first()->getFirstMedia(Message::FILES));

        $this->actingAs($this->seat)->get($url)->assertOk();
        $this->actingAs(adminUser(AdminRole::CommunityManager))->get($url)->assertOk();
        $this->actingAs($this->dm)->get($url)->assertForbidden();
        $this->actingAs($this->seat2)->get($url)->assertForbidden();
        $this->actingAs($this->owner)->get($url)->assertForbidden();
    });

    it('rejects disallowed or oversized files and leaves no half-posted message', function () {
        expect(fn () => ($this->post)($this->seat, 'Run this', [UploadedFile::fake()->create('run.php', 1)]))
            ->toThrow(ValidationException::class)
            ->and(fn () => ($this->post)($this->seat, 'Too big', [UploadedFile::fake()->create('big.pdf', 21 * 1024, 'application/pdf')]))
            ->toThrow(ValidationException::class)
            ->and(Message::query()->count())->toBe(0);
    });

    it('limits files per message, message length, empty messages and speed', function () {
        $files = fn (int $n) => array_map(fn ($i) => UploadedFile::fake()->create("f{$i}.pdf", 1, 'application/pdf'), range(1, $n));

        expect(fn () => ($this->post)($this->seat, 'Many', $files(6)))->toThrow(ActionBlocked::class)
            ->and(fn () => ($this->post)($this->seat, '   '))->toThrow(ActionBlocked::class)
            ->and(fn () => ($this->post)($this->seat, str_repeat('a', 5001)))->toThrow(ActionBlocked::class);

        RateLimiter::clear('message:'.$this->seat->id);

        foreach (range(1, 20) as $i) {
            ($this->post)($this->seat, "Message {$i}");
        }

        expect(fn () => ($this->post)($this->seat, 'One too many'))->toThrow(ActionBlocked::class, 'too quickly');
    });

    it('escapes message text and keeps it out of the activity log', function () {
        ($this->post)($this->owner, '<script>alert(1)</script> secret plan');

        Livewire::actingAs($this->seat)->test(Conversation::class, ['thread' => $this->thread])
            ->assertDontSeeHtml('<script>alert(1)</script>');

        $logged = Activity::query()->where('log_name', 'message')->get();

        expect($logged)->toHaveCount(1)
            ->and(json_encode($logged->first()->properties))->not->toContain('secret plan');
    });

    it('refreshes by itself every 20 seconds', function () {
        Livewire::actingAs($this->owner)->test(Conversation::class, ['thread' => $this->thread])
            ->assertSeeHtml('wire:poll.20s.visible');
    });

    it('pages back through a long conversation', function () {
        Message::factory()->count(55)->create(['thread_id' => $this->thread->id, 'user_id' => $this->dm->id]);

        Livewire::actingAs($this->owner)->test(Conversation::class, ['thread' => $this->thread])
            ->assertSee('Show earlier messages')
            ->call('showEarlier')
            ->assertDontSee('Show earlier messages');
    });
});

describe('opening the conversation with the SOW', function () {
    it('creates the project thread when the SOW is sent, before the client sees anything', function () {
        $project = Project::factory()->status(ProjectStatus::Draft)->create(['organization_id' => $this->org->id, 'delivery_manager_id' => $this->dm->id]);
        $sow = Sow::factory()->create(['project_id' => $project->id, 'version' => 1]);

        expect(Thread::query()->where('project_id', $project->id)->exists())->toBeFalse();

        app(SendSow::class)($sow, $this->dm);

        $thread = Thread::query()->where('project_id', $project->id)->first();

        expect($thread)->not->toBeNull()
            ->and($this->owner->can('view', $thread))->toBeTrue();
    });
});
