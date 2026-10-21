<?php

use App\Services\Ops\CronHeartbeat;
use Illuminate\Support\Facades\Schedule;

// Proof that cron is calling `schedule:run`; the admin System status widget reads it (spec 17.4).
Schedule::call(fn () => app(CronHeartbeat::class)->record())
    ->everyMinute()
    ->name('cron-heartbeat');

// Shared hosting: no persistent workers. Cron runs `schedule:run` every minute (spec 17.1).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Overdue task digests for delivery managers, once a day (Pass 4).
Schedule::command('groibe:notify-overdue-tasks')
    ->dailyAt('08:00')
    ->withoutOverlapping(30);

// Invoice references past their due date become overdue, with one digest to finance (SCR-024).
Schedule::command('groibe:mark-overdue-invoices')
    ->dailyAt('08:15')
    ->withoutOverlapping(30);

// Spec 17.4 and SCR-032, SCR-034: daily housekeeping, in the application time zone. Overnight jobs are spaced out so they
// never compete for a shared host's memory; the stale invite report goes out in working hours, when someone can act on it.
Schedule::command('groibe:prune-email-log')->dailyAt('03:00')->withoutOverlapping(30);
Schedule::command('groibe:clean-import-files')->dailyAt('03:10')->withoutOverlapping(30);
Schedule::command('groibe:check-database-size')->dailyAt('03:20')->withoutOverlapping(30);
Schedule::command('groibe:refresh-public-caches')->dailyAt('03:30')->withoutOverlapping(30);
Schedule::command('groibe:report-stale-invites')->dailyAt('08:30')->withoutOverlapping(30);

// Weekly database dump outside the web root (spec 17.4), on top of the host's own daily backups.
Schedule::command('groibe:backup-db')->weeklyOn(0, '02:30')->withoutOverlapping(60);

// SCR-039: retention for the queue tables, read notifications and the audit trail (days are settings), and the daily reminder
// of data requests that have waited too long. Spaced after the other overnight jobs; the reminder follows the stale invite report.
Schedule::command('groibe:prune-queue-history')->dailyAt('03:40')->withoutOverlapping(30);
Schedule::command('groibe:prune-notifications')->dailyAt('03:45')->withoutOverlapping(30);
Schedule::command('groibe:prune-activity-log')->dailyAt('03:50')->withoutOverlapping(30);
Schedule::command('groibe:remind-data-requests')->dailyAt('08:45')->withoutOverlapping(30);
