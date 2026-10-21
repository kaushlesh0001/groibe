<?php

use App\Models\Setting;
use App\Services\Ops\Retention;
use App\Services\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        activity('test')->log('forty days');
        Activity::query()->where('description', 'forty days')->update(['created_at' => now()->subDays(40)]);
        setRetention('activity_log_days', '30');

        $this->artisan('groibe:prune-activity-log')->assertSuccessful();

        expect(Activity::query()->where('description', 'forty days')->exists())->toBeFalse();
    });
});
