<?php

use App\Enums\AdminRole;
use App\Enums\DataRequestStatus;
use App\Models\DataRequest;
use App\Notifications\DataRequestsWaiting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Cache::flush();
    Notification::fake();

    $this->super = adminUser();
    $this->manager = adminUser(AdminRole::DeliveryManager);
    $this->community = adminUser(AdminRole::CommunityManager);
});

function requestMadeDaysAgo(int $days, DataRequestStatus $status = DataRequestStatus::Open): DataRequest
{
    return DataRequest::factory()->status($status)->create(['created_at' => now()->subDays($days)]);
}

it('reminds super admins, on both channels, of a request that has waited more than 7 days', function () {
    requestMadeDaysAgo(9);
    requestMadeDaysAgo(8, DataRequestStatus::InProgress);

    $this->artisan('groibe:remind-data-requests')->assertSuccessful();

    Notification::assertSentTo($this->super, DataRequestsWaiting::class, function (DataRequestsWaiting $notice, array $channels): bool {
        return $channels === ['database', 'mail']
            && $notice->total === 2
            && $notice->oldestDays === 9
            && $notice->reminderDays === 7;
    });
    Notification::assertNotSentTo($this->manager, DataRequestsWaiting::class);
    Notification::assertNotSentTo($this->community, DataRequestsWaiting::class);
});

it('sends nothing when no request has waited more than 7 days, or every old one is finished', function () {
    requestMadeDaysAgo(6);
    requestMadeDaysAgo(30, DataRequestStatus::Completed);
    requestMadeDaysAgo(30, DataRequestStatus::Rejected);

    $this->artisan('groibe:remind-data-requests')->assertSuccessful();

    Notification::assertNothingSent();
});

it('reminds once a day even when the command runs again', function () {
    requestMadeDaysAgo(10);

    $this->artisan('groibe:remind-data-requests')->assertSuccessful();
    $this->artisan('groibe:remind-data-requests')->assertSuccessful();

    Notification::assertSentToTimes($this->super, DataRequestsWaiting::class, 1);

    $this->travel(1)->days();
    $this->artisan('groibe:remind-data-requests')->assertSuccessful();

    Notification::assertSentToTimes($this->super, DataRequestsWaiting::class, 2);
});

it('carries a count and an age and never a name or a request', function () {
    $request = requestMadeDaysAgo(10);

    $notice = new DataRequestsWaiting(1, 10, 7);
    $text = json_encode($notice->toArray($this->super)).$notice->toMail($this->super)->render();

    expect($text)->not->toContain($request->person()->email)
        ->and($text)->not->toContain($request->person()->name)
        ->and($notice->category())->toBe('security');
});
