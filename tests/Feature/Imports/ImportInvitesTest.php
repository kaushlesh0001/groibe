<?php

use App\Actions\Imports\QueueImportInvites;
use App\Actions\Onboarding\QueueInvites;
use App\Actions\Onboarding\SendInvite;
use App\Enums\AdminRole;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\OnboardingState;
use App\Enums\UserStatus;
use App\Exceptions\IllegalTransition;
use App\Filament\Admin\Resources\Contributors\Pages\ListContributors;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Jobs\SendQueuedInvite;
use App\Models\Import;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\InviteNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** A pending contributor waiting for an invite. */
function waitingContributor(): User
{
    $user = User::factory()->contributor()->pending()->create(['password' => null]);
    $user->forceFill(['password' => null])->save();

    return $user;
}

describe('queueing invites', function () {
    it('spreads the emails out at the configured rate', function () {
        Queue::fake();
        config(['groibe.invites.per_minute' => 30]);
        $admin = adminUser();
        $people = collect(range(1, 4))->map(fn () => waitingContributor());

        $result = app(QueueInvites::class)($people, $admin);

        expect($result)->toBe(['queued' => 4, 'skipped' => 0]);

        $delays = [];
        Queue::assertPushed(SendQueuedInvite::class, function (SendQueuedInvite $job) use (&$delays): bool {
            $delays[] = (int) round(now()->diffInSeconds($job->delay, false));

            return true;
        });

        // 30 a minute is one every two seconds.
        expect($delays)->toEqual([0, 2, 4, 6]);
    });

    it('is slower when the limit is lower', function () {
        Queue::fake();
        config(['groibe.invites.per_minute' => 6]);

        app(QueueInvites::class)(collect([waitingContributor(), waitingContributor(), waitingContributor()]), adminUser());

        $delays = [];
        Queue::assertPushed(SendQueuedInvite::class, function (SendQueuedInvite $job) use (&$delays): bool {
            $delays[] = (int) round(now()->diffInSeconds($job->delay, false));

            return true;
        });

        expect($delays)->toEqual([0, 10, 20]);
    });

    it('skips people who are not waiting for an invite', function () {
        Queue::fake();
        $active = User::factory()->contributor()->create();
        $hasPassword = User::factory()->contributor()->pending()->create();
        $suspended = User::factory()->contributor()->suspended()->create(['password' => null]);
        $admin = adminUser(AdminRole::CommunityManager);

        $result = app(QueueInvites::class)(collect([waitingContributor(), $active, $hasPassword, $suspended, $admin]), $admin);

        expect($result)->toBe(['queued' => 1, 'skipped' => 4]);
        Queue::assertPushed(SendQueuedInvite::class, 1);
    });

    it('skips people the admin may not update', function () {
        Queue::fake();
        $client = User::factory()->client()->pending()->create();
        $client->forceFill(['password' => null])->save();

        $communityManager = app(QueueInvites::class)(collect([$client, waitingContributor()]), adminUser(AdminRole::CommunityManager));
        $deliveryManager = app(QueueInvites::class)(collect([$client]), adminUser(AdminRole::DeliveryManager));

        expect($communityManager)->toBe(['queued' => 1, 'skipped' => 1])
            ->and($deliveryManager)->toBe(['queued' => 1, 'skipped' => 0]);
    });

    it('queues nothing for an admin who may update nobody', function () {
        Queue::fake();

        $result = app(QueueInvites::class)(collect([waitingContributor()]), adminUser(AdminRole::FinanceOps));

        expect($result)->toBe(['queued' => 0, 'skipped' => 1]);
        Queue::assertNothingPushed();
    });
});

describe('sending one invite', function () {
    it('emails a signed link that expires in seven days', function () {
        Notification::fake();
        $user = waitingContributor();

        (new SendQueuedInvite($user->id, adminUser()->id))->handle(app(SendInvite::class));

        Notification::assertSentTo($user, InviteNotification::class, function (InviteNotification $notification): bool {
            parse_str((string) parse_url($notification->url, PHP_URL_QUERY), $query);

            return str_contains($notification->url, '/invite/')
                && isset($query['signature'])
                && (int) $query['expires'] > now()->addDays(6)->getTimestamp()
                && (int) $query['expires'] <= now()->addDays(7)->getTimestamp();
        });
    });

    it('does nothing for someone who activated in the meantime or was removed', function () {
        Notification::fake();
        $activated = User::factory()->contributor()->create();
        $removed = waitingContributor();
        $removedId = $removed->id;
        $removed->forceDelete();

        $job = fn (int $id) => (new SendQueuedInvite($id, adminUser()->id))->handle(app(SendInvite::class));
        $job($activated->id);
        $job($removedId);

        Notification::assertNothingSent();
    });
});

describe('invites for one import', function () {
    it('queues only the people that import created', function () {
        Queue::fake([SendQueuedInvite::class]);
        $admin = adminUser();
        $stranger = waitingContributor();

        $import = runImport(uploadImport($admin, ImportType::Contributors, importCsv(['name', 'email'], [['Asha Rao', 'asha@example.com'], ['Ravi Nair', 'ravi@example.com']])), $admin);
        $other = runImport(uploadImport($admin, ImportType::Contributors, importCsv(['name', 'email'], [['Sam Das', 'sam@example.com']])), $admin);

        $result = app(QueueImportInvites::class)($import, $admin);

        expect($result)->toBe(['queued' => 2, 'skipped' => 0]);
        Queue::assertPushed(SendQueuedInvite::class, 2);
        Queue::assertNotPushed(SendQueuedInvite::class, fn (SendQueuedInvite $job) => $job->userId === $stranger->id || $job->userId === User::query()->where('email', 'sam@example.com')->value('id'));
        expect($other->status)->toBe(ImportStatus::Completed);
    });

    it('queues people created through a client import, with their organization', function () {
        Queue::fake([SendQueuedInvite::class]);
        $admin = adminUser();

        $import = runImport(uploadImport($admin, ImportType::Clients, importCsv(['organization_name', 'contact_name', 'contact_email'], [['Acme', 'Meera', 'meera@acme.in'], ['Acme', 'Dev', 'dev@acme.in']])), $admin);

        expect(app(QueueImportInvites::class)($import, $admin))->toBe(['queued' => 2, 'skipped' => 0])
            ->and(Organization::query()->count())->toBe(1);
    });

    it('skips people who signed up since the import', function () {
        Queue::fake([SendQueuedInvite::class]);
        $admin = adminUser();
        $import = runImport(uploadImport($admin, ImportType::Contributors, importCsv(['name', 'email'], [['Asha Rao', 'asha@example.com'], ['Ravi Nair', 'ravi@example.com']])), $admin);

        User::query()->where('email', 'asha@example.com')->firstOrFail()->forceFill(['password' => 'a-long-password-1', 'status' => UserStatus::Active])->save();

        expect(app(QueueImportInvites::class)($import, $admin))->toBe(['queued' => 1, 'skipped' => 1]);
    });

    it('refuses before the import has finished', function (ImportStatus $status) {
        $admin = adminUser();
        $import = Import::factory()->status($status)->create(['created_by' => $admin->id]);

        expect(fn () => app(QueueImportInvites::class)($import, $admin))->toThrow(IllegalTransition::class);
    })->with([ImportStatus::Uploaded, ImportStatus::Validated, ImportStatus::Queued, ImportStatus::Running, ImportStatus::Failed]);

    it('refuses an admin who does not own the import', function () {
        $import = Import::factory()->status(ImportStatus::Completed)->create();

        expect(fn () => app(QueueImportInvites::class)($import, adminUser(AdminRole::DeliveryManager)))->toThrow(AuthorizationException::class);
    });
});

describe('bulk actions on the lists', function () {
    it('sends invites to the selected contributors', function () {
        Queue::fake([SendQueuedInvite::class]);
        $waiting = waitingContributor();
        $active = User::factory()->contributor()->create();
        $profiles = collect([$waiting, $active])->map(fn (User $user) => Profile::factory()->inState(OnboardingState::Registered)->create(['user_id' => $user->id]));

        Livewire::actingAs(adminUser())->test(ListContributors::class)
            ->set('activeTab', 'all')
            ->callTableBulkAction('sendInvites', $profiles)
            ->assertNotified();

        Queue::assertPushed(SendQueuedInvite::class, 1);
        Queue::assertPushed(SendQueuedInvite::class, fn (SendQueuedInvite $job) => $job->userId === $waiting->id);
    });

    it('sends invites to the selected users', function () {
        Queue::fake([SendQueuedInvite::class]);
        $waiting = waitingContributor();

        Livewire::actingAs(adminUser())->test(ListUsers::class)
            ->callTableBulkAction('sendInvites', [$waiting])
            ->assertNotified();

        Queue::assertPushed(SendQueuedInvite::class, 1);
    });

    it('queues nothing when a community manager selects clients', function () {
        Queue::fake([SendQueuedInvite::class]);
        $client = User::factory()->client()->pending()->create();
        $client->forceFill(['password' => null])->save();

        Livewire::actingAs(adminUser(AdminRole::CommunityManager))->test(ListUsers::class)
            ->callTableBulkAction('sendInvites', [$client]);

        Queue::assertNothingPushed();
    });
});
