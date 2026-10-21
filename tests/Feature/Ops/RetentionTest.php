<?php

use App\Enums\SettingType;
use App\Filament\Admin\Resources\Settings\Pages\CreateSetting;
use App\Filament\Admin\Resources\Settings\Schemas\SettingForm;
use App\Models\Setting;
use App\Services\Ops\Retention;
use App\Services\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Cache::flush();
});

function failedJob(int $daysAgo): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays($daysAgo),
    ]);
}

function jobBatch(int $daysAgo): void
{
    DB::table('job_batches')->insert([
        'id' => (string) str()->uuid(), 'name' => 'b', 'total_jobs' => 1, 'pending_jobs' => 0, 'failed_jobs' => 0,
        'failed_job_ids' => '[]', 'created_at' => now()->subDays($daysAgo)->getTimestamp(),
    ]);
}

function notificationRow(int $daysAgo, bool $read): void
{
    DB::table('notifications')->insert([
        'id' => (string) str()->uuid(), 'type' => 'x', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => 1,
        'data' => '{}', 'read_at' => $read ? now()->subDays($daysAgo) : null,
        'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
    ]);
}

function setRetention(string $name, string $value): void
{
    Setting::query()->create(['key' => "retention.{$name}", 'value' => $value, 'type' => 'string']);
    app(Settings::class)->flush();
}

describe('retention settings', function () {
    it('uses 30, 30, 180 and 365 days when nothing is set', function () {
        $retention = app(Retention::class);

        expect($retention->days('failed_jobs_days'))->toBe(30)
            ->and($retention->days('job_batches_days'))->toBe(30)
            ->and($retention->days('notifications_read_days'))->toBe(180)
            ->and($retention->days('activity_log_days'))->toBe(365);
    });

    it('takes a value from the settings table', function () {
        setRetention('failed_jobs_days', '10');

        expect(app(Retention::class)->days('failed_jobs_days'))->toBe(10);
    });

    it('falls back to the default for anything that is not a whole number of 1 or more', function (string $bad) {
        setRetention('notifications_read_days', $bad);

        expect(app(Retention::class)->days('notifications_read_days'))->toBe(180);
    })->with(['zero' => '0', 'negative' => '-5', 'text' => 'soon', 'decimal' => '2.5', 'empty' => '']);
});

describe('prune queue history', function () {
    it('deletes failed jobs and job batches past 30 days and keeps newer ones', function () {
        failedJob(31);
        failedJob(5);
        jobBatch(31);
        jobBatch(5);

        $this->artisan('groibe:prune-queue-history')->assertSuccessful();

        expect(DB::table('failed_jobs')->count())->toBe(1)
            ->and(DB::table('job_batches')->count())->toBe(1);

        $entry = Activity::query()->where('log_name', 'queue')->where('event', 'retention')->latest('id')->first();
        expect($entry->properties['failed_jobs_removed'])->toBe(1)
            ->and($entry->properties['job_batches_removed'])->toBe(1);
    });

    it('follows the setting and writes an audit entry even when nothing is removed', function () {
        failedJob(8);
        setRetention('failed_jobs_days', '7');

        $this->artisan('groibe:prune-queue-history')->assertSuccessful();
        expect(DB::table('failed_jobs')->count())->toBe(0);

        $this->artisan('groibe:prune-queue-history')->assertSuccessful();
        expect(Activity::query()->where('log_name', 'queue')->count())->toBe(2);
    });
});

describe('prune notifications', function () {
    it('deletes read notifications past 180 days and never an unread one', function () {
        notificationRow(181, read: true);
        notificationRow(179, read: true);
        notificationRow(400, read: false);

        $this->artisan('groibe:prune-notifications')->assertSuccessful();

        expect(DB::table('notifications')->count())->toBe(2)
            ->and(DB::table('notifications')->whereNull('read_at')->count())->toBe(1);

        $entry = Activity::query()->where('log_name', 'notifications')->latest('id')->first();
        expect($entry->properties['removed'])->toBe(1)->and($entry->properties['days'])->toBe(180);
    });

    it('works through more rows than one slice', function () {
        $rows = [];
        for ($i = 0; $i < 1005; $i++) {
            $rows[] = [
                'id' => (string) str()->uuid(), 'type' => 'x', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => 1,
                'data' => '{}', 'read_at' => now()->subDays(300), 'created_at' => now()->subDays(300), 'updated_at' => now()->subDays(300),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }

        $this->artisan('groibe:prune-notifications')->assertSuccessful();

        expect(DB::table('notifications')->count())->toBe(0);
    });
});

describe('prune activity log', function () {
    it('removes audit entries past 365 days, keeps newer ones and records the run', function () {
        activity('test')->log('old');
        activity('test')->log('recent');
        Activity::query()->where('description', 'old')->update(['created_at' => now()->subDays(366)]);
        Activity::query()->where('description', 'recent')->update(['created_at' => now()->subDays(364)]);

        $this->artisan('groibe:prune-activity-log')->assertSuccessful();

        expect(Activity::query()->where('description', 'old')->exists())->toBeFalse()
            ->and(Activity::query()->where('description', 'recent')->exists())->toBeTrue();

        $entry = Activity::query()->where('log_name', 'audit_log')->latest('id')->first();
        expect($entry)->not->toBeNull()
            ->and($entry->properties['removed'])->toBe(1)
            ->and($entry->properties['days'])->toBe(365);
    });

    it('follows the setting', function () {
        activity('test')->log('older than 100');
        activity('test')->log('younger than 100');
        Activity::query()->where('description', 'older than 100')->update(['created_at' => now()->subDays(120)]);
        Activity::query()->where('description', 'younger than 100')->update(['created_at' => now()->subDays(95)]);
        setRetention('activity_log_days', '100');

        $this->artisan('groibe:prune-activity-log')->assertSuccessful();

        expect(Activity::query()->where('description', 'older than 100')->exists())->toBeFalse()
            ->and(Activity::query()->where('description', 'younger than 100')->exists())->toBeTrue();
    });

    it('ignores a setting below the floor, so a typo cannot wipe the audit trail (SUGGESTIONS 326)', function () {
        activity('test')->log('two hundred days');
        activity('test')->log('thirty days');
        Activity::query()->where('description', 'two hundred days')->update(['created_at' => now()->subDays(200)]);
        Activity::query()->where('description', 'thirty days')->update(['created_at' => now()->subDays(30)]);
        setRetention('activity_log_days', '3');

        $this->artisan('groibe:prune-activity-log')->assertSuccessful();

        // 3 is ignored, so the default 365 days applies and nothing here is old enough to go.
        expect(Activity::query()->where('description', 'two hundred days')->exists())->toBeTrue()
            ->and(Activity::query()->where('description', 'thirty days')->exists())->toBeTrue();

        $entry = Activity::query()->where('log_name', 'audit_log')->latest('id')->first();
        expect($entry->properties['days'])->toBe(365);
    });
});

describe('retention floors (SCR-039 amendment)', function () {
    it('has the minimums 7, 7, 30 and 90 days', function () {
        $retention = app(Retention::class);

        expect($retention->minimum('failed_jobs_days'))->toBe(7)
            ->and($retention->minimum('job_batches_days'))->toBe(7)
            ->and($retention->minimum('notifications_read_days'))->toBe(30)
            ->and($retention->minimum('activity_log_days'))->toBe(90);
    });

    it('uses the default for a value below the floor and the value at the floor', function (string $name, string $below, string $atFloor, int $default) {
        setRetention($name, $below);
        expect(app(Retention::class)->days($name))->toBe($default);

        Setting::query()->where('key', "retention.{$name}")->update(['value' => $atFloor]);
        app(Settings::class)->flush();
        expect(app(Retention::class)->days($name))->toBe((int) $atFloor);
    })->with([
        'failed jobs' => ['failed_jobs_days', '6', '7', 30],
        'job batches' => ['job_batches_days', '1', '7', 30],
        'read notifications' => ['notifications_read_days', '29', '30', 180],
        'audit log' => ['activity_log_days', '89', '90', 365],
    ]);

    it('accepts a stored integer setting above the floor', function () {
        app(Settings::class)->set('retention.activity_log_days', 120, SettingType::Integer);

        expect(app(Retention::class)->days('activity_log_days'))->toBe(120);
    });

    it('applies the floor to the failed jobs and notification jobs too', function () {
        failedJob(10);
        setRetention('failed_jobs_days', '2');
        notificationRow(40, read: true);
        setRetention('notifications_read_days', '5');

        $this->artisan('groibe:prune-queue-history')->assertSuccessful();
        $this->artisan('groibe:prune-notifications')->assertSuccessful();

        // 2 and 5 are ignored: 10 days is inside the 30-day default and 40 days is inside the 180-day default.
        expect(DB::table('failed_jobs')->count())->toBe(1)
            ->and(DB::table('notifications')->count())->toBe(1);
    });

    it('says on the Settings screen what the minimums and defaults are', function () {
        $text = SettingForm::retentionNote();

        expect($text)->toContain('below the minimum is ignored')
            ->and($text)->toContain('at least 7 (default 30)')
            ->and($text)->toContain('at least 30 (default 180)')
            ->and($text)->toContain('at least 90 (default 365)');

        Livewire::actingAs(adminUser())->test(CreateSetting::class)
            ->assertSee('retention.activity_log_days')
            ->assertSee('below the minimum is ignored');
    });

    it('keeps the screen text in step with the config', function () {
        config(['groibe.ops.retention_min.activity_log_days' => 120, 'groibe.ops.retention.activity_log_days' => 400]);

        expect(SettingForm::retentionNote())->toContain('at least 120 (default 400)');
    });

});
