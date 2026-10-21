<?php

use App\Filament\Admin\Widgets\SystemStatusWidget;
use App\Models\DataRequest;
use App\Services\Ops\CronHeartbeat;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    $this->actingAs(adminUser());
});

it('raises no alert while cron has beaten within 10 minutes', function () {
    app(CronHeartbeat::class)->record();
    $this->travel(9)->minutes();

    Livewire::test(SystemStatusWidget::class)->assertSee('Running')->assertDontSee('Alert: cron');
});

it('raises an alert saying how many minutes once the heartbeat is over 10 minutes old', function () {
    app(CronHeartbeat::class)->record();
    $this->travel(12)->minutes();

    Livewire::test(SystemStatusWidget::class)
        ->assertSee('Alert: cron has not run for 12 minutes')
        ->assertSee('Queued email and every scheduled job are stopped');
});

it('does not treat no recorded run as an alert', function () {
    Livewire::test(SystemStatusWidget::class)->assertSee('No run recorded yet')->assertDontSee('Alert: cron');
});

it('counts data requests that have waited more than 7 days on System status', function () {
    DataRequest::factory()->count(2)->create(['created_at' => now()->subDays(8)]);
    DataRequest::factory()->create(['created_at' => now()->subDays(3)]);

    Livewire::test(SystemStatusWidget::class)->assertSee('Data requests waiting over 7 days');
    expect(DataRequest::query()->waitingLongerThan(7)->count())->toBe(2);
});

it('uses 10 minutes as the stopped threshold', function () {
    expect(CronHeartbeat::STALE_AFTER_MINUTES)->toBe(10);
});
