<?php

use Illuminate\Console\Scheduling\Schedule;

it('schedules every housekeeping job and the weekly backup at their times', function (string $command, string $expression) {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, $command.' ') || str_ends_with((string) $event->command, $command));

    expect($event)->not->toBeNull("{$command} is not scheduled")
        ->and($event->expression)->toBe($expression)
        ->and($event->withoutOverlapping)->toBeTrue();
})->with([
    'email log retention' => ['groibe:prune-email-log', '0 3 * * *'],
    'import file clean-up' => ['groibe:clean-import-files', '10 3 * * *'],
    'database size check' => ['groibe:check-database-size', '20 3 * * *'],
    'public cache refresh' => ['groibe:refresh-public-caches', '30 3 * * *'],
    'stale invite report' => ['groibe:report-stale-invites', '30 8 * * *'],
    'queue history retention' => ['groibe:prune-queue-history', '40 3 * * *'],
    'read notification retention' => ['groibe:prune-notifications', '45 3 * * *'],
    'audit log retention' => ['groibe:prune-activity-log', '50 3 * * *'],
    'data request reminder' => ['groibe:remind-data-requests', '45 8 * * *'],
    'weekly database dump' => ['groibe:backup-db', '30 2 * * 0'],
]);

it('keeps the jobs that already existed', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");

    expect($commands)->toContain('queue:work')
        ->and($commands)->toContain('groibe:notify-overdue-tasks')
        ->and($commands)->toContain('groibe:mark-overdue-invoices');
});
