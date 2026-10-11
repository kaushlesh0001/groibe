<?php

use App\Actions\Messages\GetProjectThread;
use App\Actions\Messages\PostMessage;
use App\Actions\Messages\StartSupportThread;
use App\Enums\AdminRole;
use App\Enums\AssignmentStatus;
use App\Enums\FeatureFlag;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Filament\Admin\Resources\Threads\ThreadResource;
use App\Livewire\Shared\Messages\Conversation;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Thread;
use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();

    $this->org = Organization::factory()->verified()->create();
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['organization_id' => $this->org->id, 'delivery_manager_id' => $this->dm->id]);

    $this->owner = clientUser($this->org, OrgRole::Owner);
    $this->manager = clientUser($this->org, OrgRole::Manager);
    $this->viewer = clientUser($this->org, OrgRole::Viewer);
    $this->otherClient = clientUser();

    $this->seat = contributorIn(OnboardingState::Active);
    Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->seat->id]);
    $this->outsider = contributorIn(OnboardingState::Active);

    $this->thread = app(GetProjectThread::class)->ensure($this->project, $this->dm);
});

describe('project thread participants', function () {
    it('lets exactly the people on the project open it', function (string $who, bool $allowed) {
        $actor = match ($who) {
            'delivery_manager' => $this->dm,
            'super_admin' => adminUser(AdminRole::SuperAdmin),
            'community_manager' => adminUser(AdminRole::CommunityManager),
            'finance_ops' => adminUser(AdminRole::FinanceOps),
            'client_owner' => $this->owner,
            'client_manager' => $this->manager,
            'client_viewer' => $this->viewer,
            'other_org_client' => $this->otherClient,
            'seated_contributor' => $this->seat,
            'outsider_contributor' => $this->outsider,
        };

        expect($actor->can('view', $this->thread))->toBe($allowed)
            ->and(Thread::query()->visibleTo($actor)->whereKey($this->thread->id)->exists())->toBe($allowed)
            ->and($actor->can('message', $this->project))->toBe($allowed);
    })->with([
        ['delivery_manager', true],
        ['super_admin', true],
        ['community_manager', false],
        ['finance_ops', false],
        ['client_owner', true],
        ['client_manager', true],
        ['client_viewer', false],
        ['other_org_client', false],
        ['seated_contributor', true],
        ['outsider_contributor', false],
    ]);

    it('drops a contributor the moment their seat is no longer active or completed', function (AssignmentStatus $status, bool $allowed) {
        Assignment::query()->where('user_id', $this->seat->id)->update(['status' => $status->value]);

        expect($this->seat->can('view', $this->thread))->toBe($allowed);
    })->with([
        [AssignmentStatus::Active, true],
        [AssignmentStatus::Completed, true],
        [AssignmentStatus::Invited, false],
        [AssignmentStatus::Declined, false],
        [AssignmentStatus::Removed, false],
    ]);

    it('refuses a suspended or not yet approved contributor even with a seat', function () {
        $pending = contributorIn(OnboardingState::PendingReview);
        Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $pending->id]);
        $suspended = contributorIn(OnboardingState::Suspended);
        Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $suspended->id]);

        expect($pending->can('view', $this->thread))->toBeFalse()
            ->and($suspended->can('view', $this->thread))->toBeFalse();
    });

    it('has no conversation while the project is a draft', function () {
        $draft = Project::factory()->status(ProjectStatus::Draft)->create(['organization_id' => $this->org->id]);

        expect(fn () => app(GetProjectThread::class)->ensure($draft, $this->dm))->toThrow(ActionBlocked::class)
            ->and($this->owner->can('message', $draft))->toBeFalse();
    });

    it('keeps one thread per project however often it is opened', function () {
        app(GetProjectThread::class)($this->project, $this->owner);
        app(GetProjectThread::class)($this->project, $this->seat);

        expect(Thread::query()->where('project_id', $this->project->id)->count())->toBe(1);
    });

    it('refuses to create or open the thread for someone who is not on the project', function () {
        expect(fn () => app(GetProjectThread::class)($this->project, $this->otherClient))->toThrow(AuthorizationException::class)
            ->and(fn () => app(GetProjectThread::class)($this->project, $this->outsider))->toThrow(AuthorizationException::class);
    });

    it('is read-only for clients and contributors once the project is closed, but admins can still post', function () {
        $this->project->update(['status' => ProjectStatus::Closed]);

        expect($this->owner->can('view', $this->thread))->toBeTrue()
            ->and($this->owner->can('reply', $this->thread))->toBeFalse()
            ->and($this->seat->can('reply', $this->thread))->toBeFalse()
            ->and($this->dm->can('reply', $this->thread))->toBeTrue();
    });
});

describe('thread pages', function () {
    it('opens the conversation for people on the project', function () {
        $this->actingAs($this->owner)->get(route('client.messages.show', $this->thread))->assertOk();
        $this->actingAs($this->seat)->get(route('contributor.messages.show', $this->thread))->assertOk();
    });

    it('answers 404 to anyone else, and a redirect to a guest', function () {
        $this->actingAs($this->otherClient)->get(route('client.messages.show', $this->thread))->assertNotFound();
        $this->actingAs($this->viewer)->get(route('client.messages.show', $this->thread))->assertNotFound();
        $this->actingAs($this->outsider)->get(route('contributor.messages.show', $this->thread))->assertNotFound();

        auth()->logout();
        $this->get(route('client.messages.show', $this->thread))->assertRedirect(route('login'));
    });

    it('keeps each role on its own side', function () {
        $this->actingAs($this->owner)->get(route('contributor.messages.show', $this->thread))->assertForbidden();
        $this->actingAs($this->seat)->get(route('client.messages.show', $this->thread))->assertForbidden();
    });

    it('opens the project thread from the project page link, and 404s for strangers', function () {
        $this->actingAs($this->owner)->get(route('client.messages.project', $this->project))->assertRedirect(route('client.messages.show', $this->thread));
        $this->actingAs($this->seat)->get(route('contributor.messages.project', $this->project))->assertRedirect(route('contributor.messages.show', $this->thread));
        $this->actingAs($this->otherClient)->get(route('client.messages.project', $this->project))->assertNotFound();
        $this->actingAs($this->outsider)->get(route('contributor.messages.project', $this->project))->assertNotFound();
    });

    it('loses access on the next refresh when the seat is removed', function () {
        $component = Livewire::actingAs($this->seat)->test(Conversation::class, ['thread' => $this->thread])->assertOk();

        Assignment::query()->where('user_id', $this->seat->id)->update(['status' => AssignmentStatus::Removed->value]);

        $component->call('$refresh')->assertNotFound();
    });

    it('lists only my own conversations in the inbox', function () {
        $mine = Thread::factory()->create(['created_by' => $this->seat->id, 'subject' => 'My own question']);
        Thread::factory()->create(['created_by' => $this->outsider->id, 'subject' => 'Somebody else question']);

        $this->actingAs($this->seat)->get(route('contributor.messages.index'))
            ->assertOk()->assertSee($mine->subject)->assertSee($this->thread->subject)->assertDontSee('Somebody else question');
    });
});

describe('support threads', function () {
    beforeEach(function () {
        $this->support = app(StartSupportThread::class)($this->seat, 'Payout question', 'When is my payout due?');
        $this->community = adminUser(AdminRole::CommunityManager);
    });

    it('is private to its owner and the admins who answer support', function (string $who, bool $allowed) {
        $actor = match ($who) {
            'owner' => $this->seat,
            'community_manager' => $this->community,
            'super_admin' => adminUser(AdminRole::SuperAdmin),
            'delivery_manager' => $this->dm,
            'finance_ops' => adminUser(AdminRole::FinanceOps),
            'other_contributor' => $this->outsider,
            'client_owner' => $this->owner,
        };

        expect($actor->can('view', $this->support))->toBe($allowed)
            ->and(Thread::query()->visibleTo($actor)->whereKey($this->support->id)->exists())->toBe($allowed);
    })->with([
        ['owner', true],
        ['community_manager', true],
        ['super_admin', true],
        ['delivery_manager', false],
        ['finance_ops', false],
        ['other_contributor', false],
        ['client_owner', false],
    ]);

    it('is a 404 for another contributor and for a client', function () {
        $this->actingAs($this->outsider)->get(route('contributor.messages.show', $this->support))->assertNotFound();
        $this->actingAs($this->owner)->get(route('client.messages.show', $this->support))->assertNotFound();
    });

    it('is open to a contributor who is not approved yet, and to clients', function () {
        $pending = contributorIn(OnboardingState::PendingReview);

        $mine = app(StartSupportThread::class)($pending, 'Help with onboarding', 'I need a hand.');
        $clientThread = app(StartSupportThread::class)($this->viewer, 'Add a colleague', 'Please add my colleague.');

        expect($pending->can('view', $mine))->toBeTrue()
            ->and($this->viewer->can('view', $clientThread))->toBeTrue()
            ->and($this->owner->can('view', $clientThread))->toBeFalse();
    });

    it('cannot be started by an admin, and needs a subject and a message', function () {
        expect(fn () => app(StartSupportThread::class)($this->community, 'Hello', 'Hi'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(StartSupportThread::class)($this->seat, 'ab', 'Hi'))->toThrow(ValidationException::class)
            ->and(fn () => app(StartSupportThread::class)($this->seat, 'A fine subject', '   '))->toThrow(ActionBlocked::class);

        // The failed start left nothing behind.
        expect(Thread::query()->where('subject', 'A fine subject')->exists())->toBeFalse();
    });

    it('lets only a support admin or the owner reply', function () {
        expect($this->seat->can('reply', $this->support))->toBeTrue()
            ->and($this->community->can('reply', $this->support))->toBeTrue()
            ->and($this->dm->can('reply', $this->support))->toBeFalse()
            ->and(fn () => app(PostMessage::class)($this->support, $this->outsider, 'Hi'))->toThrow(AuthorizationException::class);
    });
});

describe('the messaging flag', function () {
    it('closes every route, the policies and the admin screen while off', function () {
        app(FeatureFlags::class)->set(FeatureFlag::Messaging, false);
        $admin = adminUser(AdminRole::SuperAdmin);

        $this->actingAs($this->owner)->get(route('client.messages.index'))->assertNotFound();
        $this->actingAs($this->owner)->get(route('client.messages.show', $this->thread))->assertNotFound();
        $this->actingAs($this->seat)->get(route('contributor.messages.project', $this->project))->assertNotFound();

        expect($this->owner->can('view', $this->thread))->toBeFalse()
            ->and($admin->can('view', $this->thread))->toBeFalse()
            ->and(fn () => app(PostMessage::class)($this->thread, $this->dm, 'Hello'))->toThrow(AuthorizationException::class);

        $this->actingAs($admin);
        expect(ThreadResource::canViewAny())->toBeFalse();
    });

    it('opens the admin screen only for staff who hold a messaging permission', function (AdminRole $role, bool $allowed) {
        $this->actingAs(adminUser($role));

        expect(ThreadResource::canViewAny())->toBe($allowed);
    })->with([
        [AdminRole::DeliveryManager, true],
        [AdminRole::CommunityManager, true],
        [AdminRole::SuperAdmin, true],
        [AdminRole::FinanceOps, false],
    ]);
});
