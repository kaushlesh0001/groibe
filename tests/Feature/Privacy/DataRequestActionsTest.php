<?php

use App\Actions\Privacy\CompleteDeletionRequest;
use App\Actions\Privacy\CompleteExportRequest;
use App\Actions\Privacy\OpenDataRequest;
use App\Actions\Privacy\PrepareDataExport;
use App\Actions\Privacy\RejectDataRequest;
use App\Actions\Privacy\StartDataRequest;
use App\Enums\AdminRole;
use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Enums\OnboardingState;
use App\Enums\UserStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\DataRequest;
use App\Models\User;
use App\Notifications\DataRequestUpdated;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/** A request that a super admin has already taken. */
function takenRequest(DataRequestType $type = DataRequestType::Export, ?User $person = null): DataRequest
{
    $request = DataRequest::factory()->status(DataRequestStatus::InProgress)->create([
        'type' => $type,
        'user_id' => ($person ?? contributorIn(OnboardingState::Active))->id,
    ]);

    return $request;
}

describe('opening a request', function () {
    it('lets a contributor and a client ask, for themselves', function (callable $person) {
        Notification::fake();
        $user = $person();

        $request = app(OpenDataRequest::class)($user, DataRequestType::Export);

        expect($request->user_id)->toBe($user->id)
            ->and($request->type)->toBe(DataRequestType::Export)
            ->and($request->status)->toBe(DataRequestStatus::Open)
            ->and($request->uuid)->toHaveLength(26)
            ->and($request->handled_by)->toBeNull();
    })->with([
        'contributor' => [fn () => contributorIn(OnboardingState::Active)],
        'contributor still in review' => [fn () => contributorIn(OnboardingState::PendingReview)],
        'client' => [fn () => clientUser()],
    ]);

    it('lets a suspended contributor ask, because they still have the right', function () {
        Notification::fake();
        $user = contributorIn(OnboardingState::Suspended);
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        expect(app(OpenDataRequest::class)($user, DataRequestType::Deletion)->status)->toBe(DataRequestStatus::Open);
    });

    it('does not let an admin or a guest-less account open one', function (AdminRole $role) {
        expect(fn () => app(OpenDataRequest::class)(adminUser($role), DataRequestType::Export))->toThrow(AuthorizationException::class)
            ->and(DataRequest::query()->count())->toBe(0);
    })->with(AdminRole::cases());

    it('allows one waiting request of each kind, and a new one once the old one is answered', function () {
        Notification::fake();
        $user = contributorIn(OnboardingState::Active);
        $open = app(OpenDataRequest::class);

        $first = $open($user, DataRequestType::Export);

        expect(fn () => $open($user, DataRequestType::Export))->toThrow(ActionBlocked::class, 'already have a request')
            ->and($open($user, DataRequestType::Deletion)->type)->toBe(DataRequestType::Deletion);

        $first->forceFill(['status' => DataRequestStatus::InProgress])->save();
        expect(fn () => $open($user, DataRequestType::Export))->toThrow(ActionBlocked::class);

        $first->forceFill(['status' => DataRequestStatus::Completed])->save();
        expect($open($user, DataRequestType::Export)->id)->not->toBe($first->id)
            ->and(DataRequest::query()->where('user_id', $user->id)->count())->toBe(3);
    });

    it('keeps one person\'s waiting request from blocking another', function () {
        Notification::fake();
        app(OpenDataRequest::class)(contributorIn(OnboardingState::Active), DataRequestType::Export);

        expect(app(OpenDataRequest::class)(contributorIn(OnboardingState::Active), DataRequestType::Export)->status)->toBe(DataRequestStatus::Open);
    });

    it('tells the super admins, and no other role', function () {
        Notification::fake();
        $super = adminUser();
        $others = collect(AdminRole::cases())->reject(fn (AdminRole $role) => $role === AdminRole::SuperAdmin)->map(fn (AdminRole $role) => adminUser($role));

        app(OpenDataRequest::class)(contributorIn(OnboardingState::Active), DataRequestType::Deletion);

        Notification::assertSentTo($super, DataRequestUpdated::class, fn (DataRequestUpdated $n) => $n->event === 'received' && $n->toAdmin);
        foreach ($others as $other) {
            Notification::assertNotSentTo($other, DataRequestUpdated::class);
        }
    });

    it('writes the request to the audit log without any personal data', function () {
        Notification::fake();
        $user = contributorIn(OnboardingState::Active, [], ['name' => 'Asha Rao', 'email' => 'asha@example.com']);

        app(OpenDataRequest::class)($user, DataRequestType::Export);

        $entries = Activity::query()->where('log_name', 'privacy')->get();

        expect($entries->pluck('event')->all())->toContain('requested')
            ->and(json_encode($entries->toArray()))->not->toContain('Asha')->not->toContain('asha@example.com');
    });

    it('keeps the notification to the kind of request, with nothing about the person', function () {
        $request = DataRequest::factory()->create();
        $notification = new DataRequestUpdated($request, 'received', toAdmin: true);

        expect($notification->category())->toBe('security')
            ->and(json_encode($notification->toArray($request->user)))->not->toContain($request->user->email)->not->toContain($request->user->name);
    });
});

describe('the state machine', function () {
    it('allows exactly the moves SCR-028 describes', function () {
        $allowed = [
            'open' => ['in_progress', 'rejected'],
            'in_progress' => ['completed', 'rejected'],
            'completed' => [],
            'rejected' => [],
        ];

        foreach (DataRequestStatus::cases() as $status) {
            expect(array_map(fn (DataRequestStatus $to) => $to->value, $status->allowedTransitions()))->toBe($allowed[$status->value]);
        }
    });

    it('moves open to in progress, and only from open', function () {
        $admin = adminUser();
        $request = DataRequest::factory()->create();

        $started = app(StartDataRequest::class)($request, $admin);

        expect($started->status)->toBe(DataRequestStatus::InProgress)->and($started->handled_by)->toBe($admin->id)
            ->and(fn () => app(StartDataRequest::class)($request->refresh(), $admin))->toThrow(IllegalTransition::class);
    });

    it('cannot be completed before it is taken', function () {
        $admin = adminUser();

        expect(fn () => app(CompleteExportRequest::class)(DataRequest::factory()->create(), $admin, 'Sent by email'))->toThrow(IllegalTransition::class)
            ->and(fn () => app(CompleteDeletionRequest::class)(DataRequest::factory()->deletion()->create(), $admin))->toThrow(IllegalTransition::class);
    });

    it('treats completed and rejected as final', function (DataRequestStatus $final) {
        $admin = adminUser();
        $request = DataRequest::factory()->status($final)->create();

        expect(fn () => app(StartDataRequest::class)($request, $admin))->toThrow(IllegalTransition::class)
            ->and(fn () => app(RejectDataRequest::class)($request, $admin, 'No'))->toThrow(IllegalTransition::class)
            ->and(fn () => app(CompleteExportRequest::class)($request, $admin, 'Sent'))->toThrow(IllegalTransition::class);
    })->with([DataRequestStatus::Completed, DataRequestStatus::Rejected]);

    it('lets a request be rejected while open and while in progress', function (DataRequestStatus $before) {
        Notification::fake();
        $request = DataRequest::factory()->status($before)->create();

        $rejected = app(RejectDataRequest::class)($request, adminUser(), 'We could not verify who you are.');

        expect($rejected->status)->toBe(DataRequestStatus::Rejected)->and($rejected->note)->toBe('We could not verify who you are.');
    })->with([DataRequestStatus::Open, DataRequestStatus::InProgress]);

    it('needs a reason to reject and a note to complete an export', function () {
        $admin = adminUser();

        expect(fn () => app(RejectDataRequest::class)(DataRequest::factory()->create(), $admin, '  '))->toThrow(ActionBlocked::class, 'reason')
            ->and(fn () => app(CompleteExportRequest::class)(takenRequest(), $admin, ''))->toThrow(ActionBlocked::class, 'how the copy was delivered');
    });

    it('completes an export with the note and tells the person', function () {
        Notification::fake();
        $admin = adminUser();
        $request = takenRequest();

        $done = app(CompleteExportRequest::class)($request, $admin, 'Emailed as a JSON attachment on 12 October.');

        expect($done->status)->toBe(DataRequestStatus::Completed)->and($done->note)->toContain('Emailed')
            ->and($done->handled_by)->toBe($admin->id)->and($done->handled_at)->not->toBeNull();

        Notification::assertSentTo($request->user, DataRequestUpdated::class, fn (DataRequestUpdated $n) => $n->event === 'completed' && ! $n->toAdmin);
    });

    it('tells the person when a request is rejected, always on both channels', function () {
        Notification::fake();
        $request = DataRequest::factory()->create();

        app(RejectDataRequest::class)($request, adminUser(), 'Not possible.');

        Notification::assertSentTo($request->user, DataRequestUpdated::class, fn (DataRequestUpdated $n) => $n->event === 'rejected' && $n->channels === ['database', 'mail']);
    });

    it('refuses to complete an erasure as an export and the other way round', function () {
        $admin = adminUser();

        expect(fn () => app(CompleteExportRequest::class)(takenRequest(DataRequestType::Deletion), $admin, 'Sent'))->toThrow(ActionBlocked::class, 'erasure request')
            ->and(fn () => app(CompleteDeletionRequest::class)(takenRequest(DataRequestType::Export), $admin))->toThrow(ActionBlocked::class, 'export request');
    });

    it('does not keep a note out of the audit log by accident: the note is never logged', function () {
        Notification::fake();
        app(CompleteExportRequest::class)(takenRequest(), adminUser(), 'SECRET DELIVERY DETAIL');

        expect(json_encode(Activity::query()->where('log_name', 'privacy')->get()->toArray()))->not->toContain('SECRET DELIVERY DETAIL');
    });
});

describe('who may process a request', function () {
    it('lets only a super admin start, complete, reject and download', function (AdminRole $role, bool $allowed) {
        Notification::fake();
        $actor = adminUser($role);
        $attempts = [
            fn () => app(StartDataRequest::class)(DataRequest::factory()->create(), $actor),
            fn () => app(RejectDataRequest::class)(DataRequest::factory()->create(), $actor, 'No'),
            fn () => app(CompleteExportRequest::class)(takenRequest(), $actor, 'Sent'),
            fn () => app(PrepareDataExport::class)(takenRequest(), $actor),
        ];

        foreach ($attempts as $attempt) {
            if ($allowed) {
                expect($attempt())->not->toBeNull();
            } else {
                expect($attempt)->toThrow(AuthorizationException::class);
            }
        }
    })->with([
        'super admin' => [AdminRole::SuperAdmin, true],
        'delivery manager' => [AdminRole::DeliveryManager, false],
        'community manager' => [AdminRole::CommunityManager, false],
        'finance' => [AdminRole::FinanceOps, false],
    ]);

    it('refuses the person themselves, and anyone else who is not an admin', function () {
        $person = contributorIn(OnboardingState::Active);
        $request = DataRequest::factory()->create(['user_id' => $person->id]);

        foreach ([$person, clientUser(), contributorUser()] as $actor) {
            expect(fn () => app(StartDataRequest::class)($request, $actor))->toThrow(AuthorizationException::class)
                ->and(fn () => app(RejectDataRequest::class)($request, $actor, 'Yes'))->toThrow(AuthorizationException::class);
        }
    });

    it('holds the permissions for super admin only', function () {
        foreach (AdminRole::cases() as $role) {
            $actor = adminUser($role);
            $expected = $role === AdminRole::SuperAdmin;

            expect($actor->can('data_requests.view'))->toBe($expected)->and($actor->can('data_requests.update'))->toBe($expected)
                ->and($actor->can('viewAny', DataRequest::class))->toBe($expected);
        }
    });

    it('lets nobody delete a request', function () {
        expect(adminUser()->can('delete', DataRequest::factory()->create()))->toBeFalse();
    });
});

describe('preparing an export', function () {
    it('only works for an export request that is in progress', function () {
        $admin = adminUser();

        expect(fn () => app(PrepareDataExport::class)(DataRequest::factory()->create(), $admin))->toThrow(IllegalTransition::class)
            ->and(fn () => app(PrepareDataExport::class)(takenRequest(DataRequestType::Deletion), $admin))->toThrow(ActionBlocked::class)
            ->and(fn () => app(PrepareDataExport::class)(DataRequest::factory()->status(DataRequestStatus::Completed)->create(), $admin))->toThrow(IllegalTransition::class);
    });

    it('logs who prepared it for which request, and nothing of what it held', function () {
        $admin = adminUser();
        $person = contributorIn(OnboardingState::Active, ['headline' => 'SECRET HEADLINE'], ['name' => 'Asha Rao']);

        $data = app(PrepareDataExport::class)(takenRequest(DataRequestType::Export, $person), $admin);

        $entry = Activity::query()->where('event', 'export_prepared')->sole();

        expect($data['profile']['headline'])->toBe('SECRET HEADLINE')
            ->and($entry->causer_id)->toBe($admin->id)
            ->and(json_encode($entry->toArray()))->not->toContain('SECRET HEADLINE')->not->toContain('Asha');
    });
});
