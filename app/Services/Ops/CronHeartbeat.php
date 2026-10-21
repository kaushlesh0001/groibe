<?php

namespace App\Services\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Proof that the host cron is calling `schedule:run` (spec 17.4 "last cron run").
 * The scheduler records a beat every minute; the admin System status widget reads it.
 */
class CronHeartbeat
{
    public const KEY = 'groibe.cron.last_run';

    /** A beat older than this many minutes means cron has stopped, and System status raises an alert (SCR-039). */
    public const STALE_AFTER_MINUTES = 10;

    public function record(): void
    {
        Cache::forever(self::KEY, now()->getTimestamp());
    }

    public function lastRun(): ?CarbonImmutable
    {
        $timestamp = Cache::get(self::KEY);

        return is_int($timestamp) ? CarbonImmutable::createFromTimestamp($timestamp) : null;
    }

    /** Whole minutes since the last beat, or null when none was ever recorded. */
    public function minutesSinceLastRun(): ?int
    {
        $last = $this->lastRun();

        return $last === null ? null : (int) $last->diffInMinutes(now(), true);
    }

    public function isHealthy(): bool
    {
        $last = $this->lastRun();

        return $last !== null && $last->greaterThanOrEqualTo(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
