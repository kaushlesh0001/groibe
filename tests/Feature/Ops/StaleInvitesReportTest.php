<?php

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\StaleInvitesReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Cache::flush();
    Notification::fake();

    $this->manager = adminUser(AdminRole::DeliveryManager);
    $this->community = adminUser(AdminRole::CommunityManager);
    $this->finance = adminUser(AdminRole::FinanceOps);
});

function invitedDaysAgo(int $days, array $attributes = []): User
{
    return User::factory()->contributor()->invited()->create(['created_at' => now()->subDays($days), ...$attributes]);
}

it('tells delivery managers once, in the bell only, who has not activated after 14 days', function () {
    invitedDaysAgo(20, ['name' => 'Asha Rao']);
    invitedDaysAgo(15, ['name' => 'Bala Iyer']);

    $this->artisan('groibe:report-stale-invites')->assertSuccessful();

    Notification::assertSentTo($this->manager, StaleInvitesReport::class, function (StaleInvitesReport $notice, array $channels): bool {
        return $channels === ['database']
            && $notice->total === 2
            && $notice->names === ['Asha Rao', 'Bala Iyer']
            && str_contains($notice->headline(), '2 people');
    });

    Notification::assertNotSentTo($this->community, StaleInvitesReport::class);
    Notification::assertNotSentTo($this->finance, StaleInvitesReport::class);
});

it('counts clients as well, and ignores anyone who is not a stale invite', function () {
    invitedDaysAgo(30);
    invitedDaysAgo(30, ['role' => UserRole::Client]);
    invitedDaysAgo(13);                                                         // still within 14 days
    User::factory()->contributor()->create(['created_at' => now()->subDays(60)]); // has a password and is active
    User::factory()->contributor()->pending()->create(['created_at' => now()->subDays(60)]); // signed up, has a password
    User::factory()->admin()->create(['password' => null, 'status' => 'pending', 'created_at' => now()->subDays(60)]);
    invitedDaysAgo(30, ['status' => 'suspended']);

    $this->artisan('groibe:report-stale-invites')->assertSuccessful();

    Notification::assertSentTo($this->manager, StaleInvitesReport::class, fn (StaleInvitesReport $notice): bool => $notice->total === 2);
});

it('lists the first few names and states the real total', function () {
    foreach (range(1, 8) as $n) {
        invitedDaysAgo(40 - $n, ['name' => "Person {$n}"]);
    }

    $this->artisan('groibe:report-stale-invites')->assertSuccessful();

    Notification::assertSentTo($this->manager, StaleInvitesReport::class, function (StaleInvitesReport $notice): bool {
        $data = $notice->toArray($this->manager);

        return $notice->total === 8
            && count($notice->names) === 5
            && str_contains($data['title'], '8 people')
            && str_contains($data['body'], 'and 3 more');
    });
});

it('never deletes or changes a person', function () {
    $stale = invitedDaysAgo(90);
    $before = User::withTrashed()->count();

    $this->artisan('groibe:report-stale-invites')->assertSuccessful();

    expect(User::withTrashed()->count())->toBe($before)
        ->and($stale->fresh()->deleted_at)->toBeNull()
        ->and($stale->fresh()->password)->toBeNull()
        ->and($stale->fresh()->status->value)->toBe('pending');
});

it('sends one notice a day, even when run again', function () {
    invitedDaysAgo(20);

    $this->artisan('groibe:report-stale-invites')->assertSuccessful();
    $this->artisan('groibe:report-stale-invites')->assertSuccessful();

    Notification::assertSentToTimes($this->manager, StaleInvitesReport::class, 1);

    $this->travel(1)->day();

    $this->artisan('groibe:report-stale-invites')->assertSuccessful();

    Notification::assertSentToTimes($this->manager, StaleInvitesReport::class, 2);
});

it('sends nothing when no invite is stale', function () {
    invitedDaysAgo(3);

    $this->artisan('groibe:report-stale-invites')->expectsOutput('No stale invites.')->assertSuccessful();

    Notification::assertNothingSent();
});

it('clears expired password-reset tokens and keeps current ones', function () {
    DB::table('password_reset_tokens')->insert([
        ['email' => 'old@example.test', 'token' => 'hash-old', 'created_at' => now()->subHours(5)],
        ['email' => 'new@example.test', 'token' => 'hash-new', 'created_at' => now()->subMinutes(5)],
    ]);

    $this->artisan('groibe:report-stale-invites')->assertSuccessful();

    expect(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['new@example.test']);
});
